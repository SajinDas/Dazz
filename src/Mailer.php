<?php
namespace Dazz\Legacy;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function processSpintax($text) {
        return preg_replace_callback('/\{(((?>[^\{\}]+)|(?R))*)\}/x', function ($t) {
            $parts = explode('|', $t[1]);
            return $parts[array_rand($parts)];
        }, $text);
    }

    public function getValidSender($senderIds, $limit = 450) {
        foreach ($senderIds as $id) {
            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM AgentsEmailTable WHERE SendedEmail = ? AND SendDate >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $count = $result['cnt'];
            $stmt->close();
            echo "    [*] Sender ID: $id has sent $count emails in the last 24 hours.\n";
            if ($count < $limit) return ['id' => $id, 'count' => $count];
        }
        return false;
    }

    public function triggerEmailSending($recipientEmail, $senderId, $lang) {
        $templateDir = 'EmailTemplate/';
        $safeLang = (!empty($lang)) ? $lang : 'en';
        
        $specificTemplate = $templateDir . 'EmailTemplate_' . $safeLang . '.html';
        $defaultTemplate  = $templateDir . 'EmailTemplate.html';
        $template_file = file_exists($specificTemplate) ? $specificTemplate : $defaultTemplate;

        // Block specific domains
        if (preg_match('/@.*(tempton\.de|uwv\.nl|mail\.pro-tec\.de|gov)/i', $recipientEmail)) return "Skipped: Blocked Domain";

        // Check if email exists to determine Update vs Insert
        $stmt = $this->db->prepare("SELECT Id FROM AgentsEmailTable WHERE EmailId = ? LIMIT 1");
        $stmt->bind_param("s", $recipientEmail);
        $stmt->execute();
        $emailexits = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // COOLDOWN CHECK: Status 2 (Blocked) or sent in last 7 days
        $stmt = $this->db->prepare("SELECT Id FROM AgentsEmailTable WHERE EmailId = ? AND (Statuss = '2' OR (SendDate >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND Statuss = '1')) LIMIT 1");
        $stmt->bind_param("s", $recipientEmail);
        $stmt->execute();
        $cooldown = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($cooldown) return "Skipped: Cooldown/Blocked";

        // Get Sender Credentials
        $stmt = $this->db->prepare("SELECT Address, Password FROM EmailIds WHERE Id = ?");
        $stmt->bind_param("i", $senderId);
        $stmt->execute();
        $sender = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sender) return "Error: Sender Creds";

        echo "    [*] Sending to: $recipientEmail using Sender ID: $senderId\n";

        $rawHtml = file_get_contents($template_file);
        $spunHtml = $this->processSpintax($rawHtml);
        
        $uniqueHash = bin2hex(random_bytes(8));
        $finalBody = $spunHtml;

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $sender['Address'];
            $mail->Password   = $sender['Password'];
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($sender['Address'], 'Sajin Das | Dazz Legacy');
            $mail->addAddress($recipientEmail);
            $mail->isHTML(true);

            $subjects = [
                'de' => ['{Vorschlag|Angebot} für Kandidaten-Outsourcing: Indien', 'Personalunterstützung aus Indien'],
                'en' => ['{Candidate|Staffing} Outsourcing Proposal: India', 'Workforce Supply from India'],
                'lv' => ['Kandidātu piesaiste no Indijas', 'Darbaspēka piegāde: Indija'],
                'sk' => ['{Candidate|Staffing} Outsourcing Proposal: India', 'Workforce Supply from India']
            ];
            
            $subjectSet = $subjects[$safeLang] ?? $subjects['en'];
            $mail->Subject = $this->processSpintax($subjectSet[array_rand($subjectSet)]);
            $mail->Body    = $finalBody;
            $mail->addCustomHeader('X-Campaign-ID', $uniqueHash);

            $mail->send();

            // UPDATE OR INSERT DB Record
            if ($emailexits) {
                $updateStmt = $this->db->prepare("UPDATE AgentsEmailTable SET SendDate = NOW(), Statuss = '1', SendedEmail = ? WHERE Id = ?");
                $updateStmt->bind_param("ii", $senderId, $emailexits['Id']);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                $insertStmt = $this->db->prepare("INSERT INTO AgentsEmailTable (EmailId, SendDate, Statuss, SendedEmail) VALUES (?, NOW(), '1', ?)");
                $insertStmt->bind_param("si", $recipientEmail, $senderId);
                $insertStmt->execute();
                $insertStmt->close();
            }

            return "Success";
        } catch (Exception $e) { 
            $errorInfo = $mail->ErrorInfo;

            // Catch Gmail Rate Limit (550 5.4.5) and flag sender count
            if (strpos($errorInfo, '5.4.5') !== false || strpos($errorInfo, 'Daily user sending limit exceeded') !== false) {
                $dummyEmail = "LIMIT_REACHED_" . $senderId . "_" . time() . "@internal.local";
                $padStmt = $this->db->prepare("INSERT INTO AgentsEmailTable (EmailId, SendDate, Statuss, SendedEmail) VALUES (?, NOW(), '0', ?)");
                $padStmt->bind_param("si", $dummyEmail, $senderId);
                //$padStmt->execute();
                $padStmt->close();
            }

            return "Mailer Error: " . $errorInfo; 
        }
    }

    
     
}