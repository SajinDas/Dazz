<?php
namespace Dazz\Legacy;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailerSk {
    private $db;

    public function __construct(\mysqli $db) {
        $this->db = $db;
    }

    /**
     * Main method to trigger the email sending process
     */
    public function triggerEmailSending($email, $senderId, $company, $contact, $occupation) {
        try {

        $templateDir = 'EmailTemplate/';
        
            // 1. Load the HTML template
            // Suggestion: Keep this in a 'templates' folder
            $templatePath = $templateDir . 'EmailTemplateSk.html';

            if (!file_exists($templatePath)) {
                throw new Exception("Email template not found at: $templatePath");
            }

            $rawHtml = file_get_contents($templatePath);

            // 2. Perform Placeholder Replacements
            $placeholders = [
                '{{Company_Name}}' => $company,
                '{{Contact_Name}}' => $contact,
                '{{Occupation}}'   => $occupation,
                '{{Year}}'         => date('2026')
            ];

            $finalHtml = strtr($rawHtml, $placeholders);

            // 3. Dynamic Subject Line
            // Using the occupation and company to bypass spam filters
            $subject = "Reliable " . $occupation . "s for " . $company." From India";

            // 4. Send the Email
            // This calls your existing internal sending logic (PHPMailer / SMTP)
            return $this->sendEmail($email, $senderId, $subject, $finalHtml);

        } catch (Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }

    /**
     * Checks if a sender has reached the daily limit (e.g., 400)
     */
     public function getValidSender($senderIds, $limit = 400) {
        foreach ($senderIds as $id) {
            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM AgentsEmailTable WHERE SendedEmail = ? AND SendDate >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $count = $result['cnt'];
            $stmt->close();
            echo "Sender ID: $id | Sent in last 24h: $count\n";
            
            if ($count < $limit) return ['id' => $id, 'count' => $count];
        }
        return false;
    }

    /**
     * Internal logic to handle the actual SMTP/API transport
     */
    private function sendEmail($to, $senderId, $subject, $body) {

    $stmt = $this->db->prepare("SELECT Id FROM AgentsEmailTable WHERE EmailId = ? LIMIT 1");
        $stmt->bind_param("s", $to);
        $stmt->execute();
        $emailexits = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // COOLDOWN CHECK: Statuss 2 (Blocked) or sent in last 7 days
        $stmt = $this->db->prepare("SELECT Id FROM AgentsEmailTable WHERE EmailId = ? AND (Statuss = '2' OR (SendDate >= DATE_SUB(NOW(), INTERVAL 5 DAY) AND Statuss = '1')) LIMIT 1");
        $stmt->bind_param("s", $to);
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

 


        $mail = new PHPMailer(true);
        try {
           // $to = trim("a.sajindas@gmail.com");
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $sender['Address'];
            $mail->Password   = $sender['Password'];
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($sender['Address'], 'Sajin Das | Dazz Legacy');
            $mail->addAddress($to);
            $mail->isHTML(true);
$uniqueHash = bin2hex(random_bytes(8));
            //$subjectSet = $subjects[$safeLang] ?? $subjects['en'];
            $mail->Subject = $subject;
            $mail->Body    = $body;
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
                $insertStmt->bind_param("si", $to, $senderId);
                $insertStmt->execute();
                $insertStmt->close();
            }
             sleep(rand(30, 45));
        return "SUCCESS"; 
    } catch (Exception $e) { 
            return "Mailer Error: " . $mail->ErrorInfo; 
        }
    }
}