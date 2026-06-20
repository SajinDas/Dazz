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

    public function getValidSender($senderIds, $limit = 400) {
        foreach ($senderIds as $id) {
            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM AgentsEmailTable WHERE SendedEmail = ? AND SendDate >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $count = $result['cnt'];
            $stmt->close();
            
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
        if (preg_match('/@(tempton\.de|uwv\.nl)$/i', $recipientEmail)) return "Skipped: Blocked Domain";

        // Check if email exists to determine Update vs Insert
        $stmt = $this->db->prepare("SELECT Id FROM AgentsEmailTable WHERE EmailId = ? LIMIT 1");
        $stmt->bind_param("s", $recipientEmail);
        $stmt->execute();
        $emailexits = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // COOLDOWN CHECK: Statuss 2 (Blocked) or sent in last 7 days
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
        $finalBody = $spunHtml . ""; // Hidden fingerprint

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

            // Added 'lv' and 'sk' to prevent errors when those languages are used
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
             

            return "Success ($safeLang)";
        } catch (Exception $e) { 
            return "Mailer Error: " . $mail->ErrorInfo; 
        }
    }

    public function triggerEmailSendingsk($recipientEmail, $senderId, $company, $contact, $occupation, $lang = 'en') 
    {
        $templateDir = 'EmailTemplate/';
        $safeLang = (!empty($lang)) ? $lang : 'en';
        
        $specificTemplate = $templateDir . 'EmailTemplate_' . $safeLang . '.html';
        $defaultTemplate  = $templateDir . 'EmailTemplate.html';
        $template_file = file_exists($specificTemplate) ? $specificTemplate : $defaultTemplate;

        // Block specific domains
        if (preg_match('/@(|tempton|uwv\.nl|mail\.pro-tec\.de)$/i', $recipientEmail)) return "Skipped: Blocked Domain";

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

        // --- PREPARE CONTENT ---
        $rawHtml = file_get_contents($template_file);
        
        // 1. Process Spintax first
        $spunHtml = $this->processSpintax($rawHtml);
        
        // 2. Replace Personalization Placeholders
        $placeholders = [
            '{{Company_Name}}' => $company,
            '{{Contact_Name}}' => $contact,
            '{{Occupation}}'   => $occupation
        ];
        $finalBody = strtr($spunHtml, $placeholders);

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

            // --- PERSONALIZED SUBJECTS ---
            $subjects = [
                'de' => ["Fachkräfte für $company", "Personalunterstützung: $occupation"],
                'en' => ["Reliable {$occupation}s for $company", "Staffing solution for your $occupation needs"],
                'lv' => ["Darbaspēks uzņēmumam $company", "Kandidāti amatam: $occupation"],
                'sk' => ["Pracovníci pre $company", "Personálna podpora: $occupation"]
            ];
            
            $subjectSet = $subjects[$safeLang] ?? $subjects['en'];
            $rawSubject = $subjectSet[array_rand($subjectSet)];
            $mail->Subject = $this->processSpintax($rawSubject);
            
            $mail->Body = $finalBody;
            $mail->addCustomHeader('X-Campaign-ID', bin2hex(random_bytes(8)));

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

            sleep(rand(30, 45));

            return "Success ($safeLang)";
        } catch (Exception $e) { 
            return "Mailer Error: " . $mail->ErrorInfo; 
        }
    }
}