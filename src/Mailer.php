<?php
namespace Dazz\Legacy;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    private $db;

    private $blockedDomainsRegex = '/@(tempton\.de|uwv\.nl|mail\.pro-tec\.de|upsvr\.gov\.sk|sluzbyzamestnanosti\.sk|nva\.gov\.lv|uradprace\.cz|mpsv\.cz|mpsv\.gov\.cz|up\.gov\.cz|ess\.gov\.si|hzz\.hr|uzt\.lt|tootukassa\.ee|eures\.ee|praca\.gov\.pl|anofm\.ro|munka\.hu|gov\.mt|arbeitsagentur\.de|europa\.eu|ec\.europa\.eu)$/i';

    public function __construct($db) {
        $this->db = $db;
    }

    public function processSpintax($text) {
        return preg_replace_callback('/\{(((?>[^\{\}]+)|(?R))*)\}/x', function ($t) {
            $parts = explode('|', $t[1]);
            return $parts[array_rand($parts)];
        }, $text);
    }

    public function isBlockedOrGovEmail($email) {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        return (bool) preg_match($this->blockedDomainsRegex, $email);
    }

    public function getValidSender($senderIds, $limit = 400) {
        foreach ($senderIds as $id) {
            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM AgentsEmailTable WHERE SendedEmail = ? AND SendDate >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $count = (int)($result['cnt'] ?? 0);
            $stmt->close();

            if ($count < $limit) {
                return ['id' => $id, 'count' => $count];
            }
        }
        return false;
    }

    public function triggerEmailSending(
        $recipientEmail,
        $senderId,
        $lang = 'en',
        $company = 'Your Organization',
        $contact = 'Hiring Manager',
        $occupation = 'Skilled Personnel'
    ) {
        $recipientEmail = trim(strtolower($recipientEmail));
        if ($this->isBlockedOrGovEmail($recipientEmail)) {
            return "Skipped: Blocked/Gov Domain";
        }

        $templateDir = 'EmailTemplate/';
        $safeLang = strtolower(trim(!empty($lang) ? $lang : 'en'));

        $legacyMap = [
            'hr' => $templateDir . 'EmailTemplate01.html',
            'sk' => $templateDir . 'EmailTemplateSk.html',
            'fr' => $templateDir . 'EmailTemplateCorsican.html',
            'de' => $templateDir . 'EmailTemplate_de.html'
        ];

        $specificTemplate = $templateDir . 'EmailTemplate_' . $safeLang . '.html';
        $defaultTemplate  = $templateDir . 'EmailTemplate.html';

        if (file_exists($specificTemplate)) {
            $template_file = $specificTemplate;
        } elseif (isset($legacyMap[$safeLang]) && file_exists($legacyMap[$safeLang])) {
            $template_file = $legacyMap[$safeLang];
        } else {
            $template_file = $defaultTemplate;
        }

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

        if ($cooldown) {
            return "Skipped: Cooldown/Blocked";
        }

        $stmt = $this->db->prepare("SELECT Address, Password FROM EmailIds WHERE Id = ?");
        $stmt->bind_param("i", $senderId);
        $stmt->execute();
        $sender = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sender) {
            return "Error: Sender Creds";
        }

        echo "    [*] Sending to: $recipientEmail using Sender ID: $senderId ($safeLang)\n";

        $rawHtml = file_get_contents($template_file);

        // 1. Sanitize contact name for natural greetings (especially Polish / German / Slovak)
        $cleanContact = trim($contact);
        if (empty($cleanContact) || strtolower($cleanContact) === 'hiring manager' || strpos($cleanContact, '@') !== false) {
            $cleanContact = ($safeLang === 'pl' || $safeLang === 'de') ? '' : 'Hiring Manager';
        }

        // 2. Perform placeholder replacement BEFORE spintax (supporting both {{...}} and {...})
        $placeholders = [
            '{{Company_Name}}' => !empty($company) ? $company : 'Your Organization',
            '{Company_Name}'   => !empty($company) ? $company : 'Your Organization',
            '{{Contact_Name}}' => $cleanContact,
            '{Contact_Name}'   => $cleanContact,
            '{{Occupation}}'   => !empty($occupation) ? $occupation : 'Skilled Personnel',
            '{Occupation}'     => !empty($occupation) ? $occupation : 'Skilled Personnel',
            '{{Year}}'         => date('Y'),
            '{Year}'           => date('Y')
        ];
        $substitutedHtml = strtr($rawHtml, $placeholders);

        // Clean up punctuation if contact was blank: "Dzień dobry ," -> "Dzień dobry,"
        $substitutedHtml = preg_replace('/\s+,\s*/', ', ', $substitutedHtml);

        // 3. Process Spintax AFTER placeholder substitution
        $finalBody = $this->processSpintax($substitutedHtml);

        // 4. Fail-safe scrubber: strip any unmatched {Tokens} so raw placeholders never leak
        $finalBody = preg_replace('/\{\{?[A-Za-z_]+\}?\}\s*/', '', $finalBody);
        $uniqueHash = bin2hex(random_bytes(8));

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

            $subjects = $this->getLocalizedSubjects($company, $occupation);
            $subjectSet = $subjects[$safeLang] ?? $subjects['en'];
            $mail->Subject = $this->processSpintax($subjectSet[array_rand($subjectSet)]);
            $mail->Body    = $finalBody;
            $mail->addCustomHeader('X-Campaign-ID', $uniqueHash);

            $mail->send();

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

    public function triggerEmailSendingsk($recipientEmail, $senderId, $company, $contact, $occupation, $lang = 'sk') {
        $result = $this->triggerEmailSending($recipientEmail, $senderId, $lang, $company, $contact, $occupation);
        if (strpos($result, 'Success') !== false) {
            sleep(rand(20, 35));
        }
        return $result;
    }

    private function getLocalizedSubjects($company, $occupation) {
        $co  = (!empty($company) && $company !== 'Your Organization' && $company !== 'Hiring Company') ? $company : '';
        $occ = (!empty($occupation) && $occupation !== 'Skilled Personnel') ? $occupation : '';

        return [
            'en' => [
                $co && $occ ? "{Reliable|Qualified} {$occ}s for {$co} – B2B Workforce Partnership" : "{Candidate|Staffing} Outsourcing Proposal: India & EU (TRC)",
                "{Skilled|Vetted} Workforce Supply from India & EU for " . ($co ?: "Your Operations")
            ],
            'de' => [
                $co ? "{Qualifizierte Fachkräfte|Personalunterstützung} für {$co} (Indien & EU-TRC)" : "{Vorschlag|Angebot} für Kandidaten-Outsourcing: Indien & EU",
                $occ ? "Personalunterstützung: {$occ} aus Indien & EU" : "Zuverlässige Arbeitskräfte für Ihr Unternehmen"
            ],
            'lv' => [
                $co ? "{Kvalificēts darbaspēks|Darbinieku piesaiste} uzņēmumam {$co}" : "Kandidātu piesaiste no Indijas un ES (TRC)",
                $occ ? "Kandidāti amatam: {$occ} (Indija / ES)" : "Darbaspēka piegāde un B2B sadarbība: Indija"
            ],
            'sk' => [
                $co ? "{Spoľahliví pracovníci|Personálna podpora} pre {$co} z Indie a EÚ (TRC)" : "{Ponuka spolupráce|Zabezpečenie pracovníkov} z Indie a EÚ",
                $occ ? "Kvalifikovaní kandidáti na pozíciu {$occ}" : "Riešenie nedostatku pracovnej sily: India & EÚ TRC"
            ],
            'cs' => [
                $co ? "{Spolehliví pracovníci|Personální podpora} pro {$co} z Indie a EU (TRC)" : "{Nabídka B2B spolupráce|Zajištění pracovníků} z Indie a EU",
                $occ ? "Kvalifikovaní kandidáti na pozici {$occ}" : "Řešení nedostatku pracovní síly: Indie a EU"
            ],
            'sl' => [
                $co ? "{Zanesljivi delavci|Kadrovska podpora} za {$co} (Indija in EU TRC)" : "{Predlog za B2B sodelovanje|Posredovanje delovne sile} iz Indije in EU",
                $occ ? "Kvalificirani kandidati za delovno mesto: {$occ}" : "Zagotavljanje kvalificirane delovne sile iz Indije"
            ],
            'hr' => [
                $co ? "{Pouzdani radnici|Kadrovska podrška} za {$co} iz Indije i EU (TRC)" : "{Prijedlog za B2B suradnju|Posredovanje radne snage} iz Indije i EU",
                $occ ? "Kvalificirani kandidati za radno mjesto: {$occ}" : "Opskrba kvalificiranom radnom snagom iz Indije"
            ],
            'lt' => [
                $co ? "{Kvalifikuoti darbuotojai|Personalo atranka} įmonei {$co} iš Indijos ir ES" : "{B2B bendradarbiavimo pasiūlymas|Darbo jėgos tiekimas} iš Indijos ir ES (TRC)",
                $occ ? "Atrinkti kandidatai pozicijai: {$occ}" : "Patikimų darbuotojų atranka iš Indijos"
            ],
            'et' => [
                $co ? "{Kvalifitseeritud tööjõud|Personalilahendus} ettevõttele {$co} (India ja EL)" : "{B2B koostööpakkumine|Tööjõu rent ja värbamine} Indiast ja EL-ist",
                $occ ? "Kandidaadid ametikohale: {$occ}" : "Usaldusväärne tööjõud Indiast ja EL-ist (TRC)"
            ],
            'pl' => [
                $co ? "{Wykwalifikowani pracownicy|Wsparcie kadrowe} dla {$co} z Indii i UE (TRC)" : "{Propozycja współpracy B2B|Rekrutacja pracowników} z Indii i UE",
                $occ ? "Sprawdzeni kandydaci na stanowisko: {$occ}" : "Dostawa wykwalifikowanej kadry z Indii oraz UE"
            ],
            'ro' => [
                $co ? "{Forță de muncă calificată|Soluții de recrutare} pentru {$co} (India & UE TRC)" : "{Propunere de colaborare B2B|Recrutare personal} din India și UE",
                $occ ? "Candidați verificați pentru postul: {$occ}" : "Furnizare de personal calificat și necalificat din India"
            ],
            'hu' => [
                $co ? "{Megbízható munkaerő|Személyzeti támogatás} a(z) {$co} részére (India & EU TRC)" : "{B2B együttműködési ajánlat|Munkaerő-közvetítés} Indiából és az EU-ból",
                $occ ? "Képzett jelöltek {$occ} munkakörbe" : "Képzett indiai és EU TRC munkavállalók biztosítása"
            ],
            'fr' => [
                $co ? "{Main-d'œuvre qualifiée|Solution de recrutement} pour {$co} (Inde & UE TRC)" : "{Proposition de partenariat B2B|Recrutement international} Inde & UE",
                $occ ? "Candidats qualifiés pour le poste de {$occ}" : "Mise à disposition de personnel qualifié d'Inde et de l'UE"
            ]
        ];
    }
}
