<?php
namespace Dazz\Legacy;

class BounceScanner {
    private $db;
    private $deleteBounces;
    private $totalDeletedLocal = 0;
    private $totalDeletedImap  = 0;

    private $ownSenderEmails = [
        'sajin.dasels@gmail.com',
        'sajin@dazzlegacy.in',
        'a.sajindas@gmail.com'
    ];

    // System/daemon addresses & Message-ID domains that must never be mistaken for bounced recipient leads
    private $ignoredDomains = [
        'googlemail.com',
        'google.com',
        'mail.gmail.com',
        'mx.google.com',
        'smtp.gmail.com',
        'dazzlegacy.in'
    ];

    private $ignoredLocalParts = [
        'mailer-daemon',
        'postmaster',
        'noreply',
        'no-reply',
        'daemon',
        'bounce',
        'bounces'
    ];

    public function __construct(\mysqli $db, $deleteBounces = true) {
        $this->db            = $db;
        $this->deleteBounces = $deleteBounces;
        $this->loadOwnSenderEmails();
    }

    /**
     * Loads all sender addresses from EmailIds table so we never accidentally block our own sender accounts
     */
    private function loadOwnSenderEmails() {
        try {
            $res = $this->db->query("SELECT Address FROM EmailIds");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $addr = strtolower(trim($row['Address'] ?? ''));
                    if (!empty($addr)) {
                        $this->ownSenderEmails[] = $addr;
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore if table query fails
        }
        $this->ownSenderEmails = array_unique($this->ownSenderEmails);
    }

    /**
     * Checks if a raw message is a "Delivery Status Notification (Failure)" or bounce message
     */
    public function isBounceNotificationMessage($rawMessage) {
        return (bool) preg_match(
            '/Delivery Status Notification\s*\(Failure\)|Delivery Status Notification|Undelivered Mail Returned to Sender|Mail delivery failed|Failure Notice|Final-Recipient:\s*rfc822|X-Failed-Recipients:/i',
            $rawMessage
        );
    }

    /**
     * Checks if an extracted email is a valid external recipient lead (not our own sender or mailer-daemon)
     */
    public function isValidBouncedLead($email) {
        $email = strtolower(trim($email, " \t\n\r\0\x0B<>'\".,;:)("));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (in_array($email, $this->ownSenderEmails, true)) {
            return false;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }
        list($local, $domain) = $parts;

        foreach ($this->ignoredLocalParts as $badLocal) {
            if ($local === $badLocal || strpos($local, $badLocal) !== false) {
                return false;
            }
        }
        if (in_array($domain, $this->ignoredDomains, true)) {
            return false;
        }

        // Ignore Message-ID strings that look like 28+ hex/random chars before @
        if (strlen($local) > 36 && preg_match('/^[0-9a-f.-]+$/i', $local)) {
            return false;
        }

        return true;
    }

    /**
     * Parses a raw RFC-822 email message or bounce snippet and extracts failed recipient email addresses
     * Specifically targets "Delivery Status Notification (Failure)" and standard RFC-3464 DSN bounces
     */
    public function extractFailedEmailsFromRawMessage($rawMessage) {
        if (!$this->isBounceNotificationMessage($rawMessage) &&
            !preg_match('/Address not found|Message not delivered/i', $rawMessage)) {
            return [];
        }

        // Decode quoted-printable soft line breaks (=\r\n or =\n and =3D / =40) so split emails are reassembled
        $decoded = preg_replace('/=\r?\n/', '', $rawMessage);
        $decoded = quoted_printable_decode($decoded);

        $found = [];

        // Priority 1: Standard RFC-3464 DSN headers & X-Failed-Recipients (100% precision)
        $headerPatterns = [
            '/Final-Recipient:\s*rfc822;\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})>?/i',
            '/Original-Recipient:\s*rfc822;\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})>?/i',
            '/X-Failed-Recipients:\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})>?/i'
        ];

        foreach ($headerPatterns as $pattern) {
            if (preg_match_all($pattern, $decoded, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $clean = strtolower(trim($candidate));
                    if ($this->isValidBouncedLead($clean)) {
                        $found[$clean] = $clean;
                    }
                }
            }
        }

        // Priority 2: Gmail / Thunderbird "Delivery Status Notification (Failure)" body patterns
        $bodyPatterns = [
            '/wasn.?t delivered to\s+<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})>?/i',
            '/was not delivered to\s+<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})>?/i',
            '/problem delivering your message to\s+<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})>?/i',
            '/Address not found[\s\S]{1,250}?\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})\b/i',
            '/Message not delivered[\s\S]{1,250}?\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})\b/i',
            '/Delivery to the following recipient(?:s)? failed permanently:[\s\S]{1,200}?\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})\b/i',
            '/<([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10})>:\s*(?:host|550|553|554|5\.1\.1|User unknown|No such user|Domain not found)/i'
        ];

        foreach ($bodyPatterns as $pattern) {
            if (preg_match_all($pattern, $decoded, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $clean = strtolower(trim($candidate));
                    if ($this->isValidBouncedLead($clean)) {
                        $found[$clean] = $clean;
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * Locates all Mozilla Thunderbird Profile directories on Windows (or custom path in Config)
     */
    public function getThunderbirdProfileDirs() {
        $dirs = [];

        if (!empty(Config::THUNDERBIRD_PROFILE_PATH) && is_dir(Config::THUNDERBIRD_PROFILE_PATH)) {
            $dirs[] = Config::THUNDERBIRD_PROFILE_PATH;
        }

        $appData = getenv('APPDATA');
        $localAppData = getenv('LOCALAPPDATA');
        $userProfile = getenv('USERPROFILE');

        $candidates = [];
        if ($appData) {
            $candidates[] = $appData . DIRECTORY_SEPARATOR . 'Thunderbird' . DIRECTORY_SEPARATOR . 'Profiles';
        }
        if ($localAppData) {
            $candidates[] = $localAppData . DIRECTORY_SEPARATOR . 'Thunderbird' . DIRECTORY_SEPARATOR . 'Profiles';
        }
        if ($userProfile) {
            $candidates[] = $userProfile . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'Roaming' . DIRECTORY_SEPARATOR . 'Thunderbird' . DIRECTORY_SEPARATOR . 'Profiles';
        }

        foreach ($candidates as $base) {
            if (is_dir($base)) {
                $subProfiles = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                if (is_array($subProfiles)) {
                    foreach ($subProfiles as $p) {
                        $dirs[] = $p;
                    }
                }
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * Step A: Scans local Mozilla Thunderbird MBOX & Maildir files for "Delivery Status Notification (Failure)"
     * AND deletes the bounce messages from Thunderbird local storage when $this->deleteBounces is true!
     */
    public function scanThunderbirdLocalProfiles($customPath = '') {
        $profileDirs = !empty($customPath) && is_dir($customPath)
            ? [$customPath]
            : $this->getThunderbirdProfileDirs();

        if (empty($profileDirs)) {
            echo "   [i] No local Thunderbird profile directory found on this machine (skipping local MBOX scan).\n";
            return [];
        }

        $discovered = [];
        $filesScanned = 0;

        foreach ($profileDirs as $profileDir) {
            echo "   [*] Scanning Thunderbird Profile: {$profileDir}\n";
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($profileDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $filePath = $fileInfo->getPathname();
                $fileName = $fileInfo->getFilename();
                $ext = strtolower($fileInfo->getExtension());

                // Only inspect mail folders inside ImapMail, Mail, or .eml files
                if (strpos($filePath, 'ImapMail') === false && strpos($filePath, 'Mail') === false && $ext !== 'eml') {
                    continue;
                }

                // Skip Thunderbird index/metadata/temp files (.msf, .dat, .json, .sqlite, .db, .tmp)
                if (in_array($ext, ['msf', 'dat', 'json', 'sqlite', 'sqlite-wal', 'sqlite-shm', 'db', 'txt', 'xml', 'ini', 'js', 'bak', 'tmp'], true)) {
                    continue;
                }

                // Focus on INBOX, All Mail, Trash, Sent, or .eml files
                if ($ext !== 'eml' && !preg_match('/^(INBOX|All Mail|Sent|Archive|Drafts)/i', $fileName)) {
                    continue;
                }
                if (stripos($fileName, 'msgFilterRules') !== false) {
                    continue;
                }

                $filesScanned++;
                echo "       -> Inspecting Thunderbird mailbox file: {$fileName} (" . round($fileInfo->getSize() / 1024 / 1024, 2) . " MB)...\n";

                if ($ext === 'eml') {
                    $rawContent = @file_get_contents($filePath);
                    if ($rawContent !== false) {
                        $emailsInEml = $this->extractFailedEmailsFromRawMessage($rawContent);
                        foreach ($emailsInEml as $bouncedEmail) {
                            $discovered[$bouncedEmail] = 'Thunderbird .eml (' . $fileName . ')';
                        }
                        if ($this->deleteBounces && (!empty($emailsInEml) || $this->isBounceNotificationMessage($rawContent))) {
                            if (@unlink($filePath)) {
                                $this->totalDeletedLocal++;
                                echo "          [DELETED .eml] Removed bounce file: {$fileName}\n";
                            }
                        }
                    }
                } else {
                    $emailsInFile = $this->scanAndCleanMailboxFileStream($filePath);
                    foreach ($emailsInFile as $bouncedEmail) {
                        $discovered[$bouncedEmail] = 'Thunderbird Local (' . $fileName . ')';
                    }
                }
            }
        }

        echo "   [+] Thunderbird Local Scan Complete: Scanned {$filesScanned} mailbox files, found "
           . count($discovered) . " bounced emails, deleted {$this->totalDeletedLocal} local bounce messages.\n";
        return $discovered;
    }

    /**
     * Streams a Thunderbird MBOX file message-by-message.
     * Extracts failed recipient emails from "Delivery Status Notification (Failure)" messages
     * AND strips those bounce messages out of the MBOX file (plus refreshes the .msf index) so they are deleted from Thunderbird!
     */
    private function scanAndCleanMailboxFileStream($filePath) {
        $found = [];
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        $tempPath = $filePath . '.dazz_clean.tmp';
        $tempHandle = $this->deleteBounces ? @fopen($tempPath, 'w') : null;

        $msgHeaderAndBodySample = '';
        $fullMessageLines = [];
        $lineCount = 0;
        $bouncesDeletedInFile = 0;

        $flushMessage = function () use (&$msgHeaderAndBodySample, &$fullMessageLines, &$found, &$bouncesDeletedInFile, $tempHandle) {
            if (empty($fullMessageLines)) {
                return;
            }

            $isBounceCandidate = (
                stripos($msgHeaderAndBodySample, 'Delivery Status Notification') !== false ||
                stripos($msgHeaderAndBodySample, 'Undelivered Mail') !== false ||
                stripos($msgHeaderAndBodySample, 'Mail delivery failed') !== false ||
                stripos($msgHeaderAndBodySample, 'Final-Recipient:') !== false
            );

            $shouldDeleteThisMessage = false;

            if ($isBounceCandidate) {
                $extracted = $this->extractFailedEmailsFromRawMessage($msgHeaderAndBodySample);
                foreach ($extracted as $e) {
                    $found[$e] = $e;
                }
                if ($this->deleteBounces && (!empty($extracted) || $this->isBounceNotificationMessage($msgHeaderAndBodySample))) {
                    $shouldDeleteThisMessage = true;
                    $bouncesDeletedInFile++;
                    $this->totalDeletedLocal++;
                }
            }

            // Write message to cleaned MBOX only if it is NOT a deleted bounce message
            if ($tempHandle && !$shouldDeleteThisMessage) {
                foreach ($fullMessageLines as $mLine) {
                    fwrite($tempHandle, $mLine);
                }
            }

            $msgHeaderAndBodySample = '';
            $fullMessageLines = [];
        };

        while (($line = fgets($handle)) !== false) {
            // In Thunderbird MBOX format, each new message starts with "From " at column 0
            if (strpos($line, 'From ') === 0 && $lineCount > 5) {
                $flushMessage();
                $fullMessageLines = [$line];
                $msgHeaderAndBodySample = $line;
                $lineCount = 1;
            } else {
                $fullMessageLines[] = $line;
                if (strlen($msgHeaderAndBodySample) < 262144) {
                    $msgHeaderAndBodySample .= $line;
                }
                $lineCount++;
            }
        }

        // Flush the final message in the MBOX file
        $flushMessage();

        fclose($handle);
        if ($tempHandle) {
            fclose($tempHandle);
        }

        if ($this->deleteBounces && file_exists($tempPath)) {
            if ($bouncesDeletedInFile > 0) {
                // Replace original Thunderbird MBOX with the cleaned MBOX (bounce messages removed)
                if (@copy($tempPath, $filePath)) {
                    @unlink($tempPath);
                    // Remove companion .msf index file so Thunderbird rebuilds its folder list without the deleted bounces
                    $msfFile = $filePath . '.msf';
                    if (file_exists($msfFile)) {
                        @unlink($msfFile);
                    }
                    echo "          [DELETED FROM THUNDERBIRD MBOX] Removed {$bouncesDeletedInFile} bounce message(s) from " . basename($filePath) . "\n";
                } else {
                    @unlink($tempPath);
                    echo "          [i] Thunderbird currently has " . basename($filePath) . " open; IMAP deletion will sync automatically.\n";
                }
            } else {
                @unlink($tempPath);
            }
        }

        return array_values($found);
    }

    /**
     * Step B: Connects directly to IMAP (imap.gmail.com:993) for every sender in EmailIds,
     * extracts failed recipient emails from "Delivery Status Notification (Failure)",
     * AND moves/deletes + expunges the bounce emails from the server so Thunderbird removes them!
     */
    public function scanImapSenderAccounts($daysBack = 7) {
        $discovered = [];

        try {
            $res = $this->db->query("SELECT Id, Address, Password FROM EmailIds");
            if (!$res) {
                return [];
            }

            while ($sender = $res->fetch_assoc()) {
                $address  = trim($sender['Address'] ?? '');
                $password = trim($sender['Password'] ?? '');
                if (empty($address) || empty($password)) {
                    continue;
                }

                echo "   [*] Checking IMAP bounces for Sender #{$sender['Id']} ({$address})...\n";

                if (function_exists('imap_open')) {
                    $bounces = $this->scanAccountViaImapExtension($address, $password, $daysBack);
                } else {
                    $bounces = $this->scanAccountViaRawSslSocket($address, $password, $daysBack);
                }

                echo "       -> Extracted " . count($bounces) . " bounced recipient(s) in {$address}.\n";
                foreach ($bounces as $bouncedEmail) {
                    $discovered[$bouncedEmail] = "IMAP ({$address})";
                }
            }
        } catch (\Exception $e) {
            echo "   [!] IMAP Scan Warning: " . $e->getMessage() . "\n";
        }

        return $discovered;
    }

    private function scanAccountViaImapExtension($address, $password, $daysBack) {
        $found = [];
        // Open in READ-WRITE mode (0) so we can delete and expunge bounce messages
        $mailbox = '{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX';
        $inbox = @imap_open($mailbox, $address, $password, 0, 1);
        if (!$inbox) {
            return [];
        }

        $sinceDate = date('d-M-Y', strtotime("-{$daysBack} days"));
        $queries = [
            'SUBJECT "Delivery Status Notification (Failure)" SINCE "' . $sinceDate . '"',
            'SUBJECT "Delivery Status Notification" SINCE "' . $sinceDate . '"',
            'FROM "mailer-daemon" SINCE "' . $sinceDate . '"'
        ];

        $msgNums = [];
        foreach ($queries as $q) {
            $res = @imap_search($inbox, $q);
            if (is_array($res)) {
                $msgNums = array_merge($msgNums, $res);
            }
        }
        $msgNums = array_unique($msgNums);
        $deletedInAccount = 0;

        foreach ($msgNums as $num) {
            $header = @imap_fetchheader($inbox, $num);
            $body   = @imap_body($inbox, $num, FT_PEEK);
            $raw    = ($header ?: '') . "\r\n" . ($body ?: '');

            $extracted = $this->extractFailedEmailsFromRawMessage($raw);
            foreach ($extracted as $e) {
                $found[$e] = $e;
            }

            if ($this->deleteBounces && (!empty($extracted) || $this->isBounceNotificationMessage($raw))) {
                // Move to Gmail Trash and mark Deleted so Thunderbird IMAP sync removes it immediately
                @imap_mail_move($inbox, (string)$num, '[Gmail]/Trash');
                @imap_delete($inbox, $num);
                $deletedInAccount++;
                $this->totalDeletedImap++;
            }
        }

        if ($this->deleteBounces && $deletedInAccount > 0) {
            @imap_expunge($inbox);
            echo "       -> [DELETED VIA IMAP] Removed {$deletedInAccount} bounce message(s) from {$address} (Thunderbird will sync automatically).\n";
        }

        @imap_close($inbox);
        return array_values($found);
    }

    /**
     * Pure-PHP SSL Socket IMAP client (works on XAMPP even when extension=imap is disabled in php.ini!)
     * Opens INBOX in Read-Write mode (SELECT INBOX), extracts bounced emails, moves to Trash, marks \Deleted, and EXPUNGEs!
     */
    private function scanAccountViaRawSslSocket($address, $password, $daysBack) {
        $found = [];
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ]);

        $fp = @stream_socket_client('ssl://imap.gmail.com:993', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return [];
        }
        stream_set_timeout($fp, 15);
        fgets($fp, 4096); // Read server greeting

        $sendCmd = function ($tag, $cmd) use ($fp) {
            fwrite($fp, "{$tag} {$cmd}\r\n");
            $response = '';
            while (($line = fgets($fp, 8192)) !== false) {
                $response .= $line;
                if (strpos($line, "{$tag} OK") === 0 || strpos($line, "{$tag} NO") === 0 || strpos($line, "{$tag} BAD") === 0) {
                    break;
                }
            }
            return $response;
        };

        $escapedUser = '"' . addslashes($address) . '"';
        $escapedPass = '"' . addslashes($password) . '"';
        $loginResp = $sendCmd('A1', "LOGIN {$escapedUser} {$escapedPass}");
        if (strpos($loginResp, 'A1 OK') === false) {
            fclose($fp);
            return [];
        }

        // Open INBOX in READ-WRITE mode (SELECT instead of EXAMINE) so we can delete & expunge bounce emails
        $sendCmd('A2', 'SELECT INBOX');
        $sinceDate = date('d-M-Y', strtotime("-{$daysBack} days"));
        $searchResp = $sendCmd('A3', 'SEARCH SUBJECT "Delivery Status Notification" SINCE ' . $sinceDate);

        $msgIds = [];
        if (preg_match('/\* SEARCH ([0-9 ]+)/', $searchResp, $m)) {
            $msgIds = array_filter(explode(' ', trim($m[1])));
        }

        // Fetch up to the 250 most recent bounce messages per account
        $msgIds = array_slice($msgIds, -250);
        $tagIdx = 10;
        $deletedIds = [];

        foreach ($msgIds as $id) {
            $rawMsg = $sendCmd('F' . ($tagIdx++), "FETCH {$id} BODY.PEEK[]");
            $extracted = $this->extractFailedEmailsFromRawMessage($rawMsg);
            foreach ($extracted as $e) {
                $found[$e] = $e;
            }

            if ($this->deleteBounces && (!empty($extracted) || $this->isBounceNotificationMessage($rawMsg))) {
                $deletedIds[] = $id;
            }
        }

        // Delete & Expunge all matched bounce messages so Thunderbird removes them on sync
        if ($this->deleteBounces && !empty($deletedIds)) {
            $idSet = implode(',', $deletedIds);
            // Apply Gmail Trash label + standard IMAP \Deleted flag, then EXPUNGE
            $sendCmd('D1', "STORE {$idSet} +X-GM-LABELS (\\Trash)");
            $sendCmd('D2', "STORE {$idSet} +FLAGS.SILENT (\\Deleted)");
            $sendCmd('D3', 'EXPUNGE');
            $countDel = count($deletedIds);
            $this->totalDeletedImap += $countDel;
            echo "       -> [DELETED VIA IMAP] Removed {$countDel} 'Delivery Status Notification (Failure)' email(s) from {$address}.\n";
        }

        $sendCmd('A99', 'LOGOUT');
        fclose($fp);
        return array_values($found);
    }

    /**
     * Master runner: Scans Thunderbird local profiles + IMAP accounts + Datas/Blocked/BlockEmail.txt,
     * deletes the bounce notifications from Thunderbird/IMAP, updates BlockEmail.txt,
     * marks all bounced emails as Statuss = '2' in AgentsEmailTable, and logs to CSV/Archive.
     */
    public function runFullBounceAndBlockSync($scanImap = true, $imapDaysBack = 7) {
        $blockDir    = 'Datas/Blocked/';
        $archiveDir  = 'Datas/Blocked/Archive/';
        $txtFile     = $blockDir . 'BlockEmail.txt';
        $archiveFile = $archiveDir . date('hmi-Y-m-d') . '_Master_Archive_Blocks.txt';
        $csvReport   = $blockDir . 'Bounce_Report_' . date('Y-m-d') . '.csv';

        if (!is_dir($blockDir)) mkdir($blockDir, 0777, true);
        if (!is_dir($archiveDir)) mkdir($archiveDir, 0777, true);
        if (!file_exists($txtFile)) file_put_contents($txtFile, '');

        echo "====================================================================\n";
        echo "  THUNDERBIRD & IMAP 'DELIVERY STATUS NOTIFICATION (FAILURE)' SCAN  \n";
        echo "  (AUTO-BLOCK FAILED RECIPIENTS + DELETE BOUNCE EMAILS ENABLED)     \n";
        echo "====================================================================\n";

        // 1. Scan & Clean Local Mozilla Thunderbird Profiles
        $tbBounces = $this->scanThunderbirdLocalProfiles();

        // 2. Scan & Clean Live IMAP Accounts (from EmailIds table)
        $imapBounces = $scanImap ? $this->scanImapSenderAccounts($imapDaysBack) : [];

        // 3. Merge newly discovered bounced emails and append them into Datas/Blocked/BlockEmail.txt
        $allDiscovered = array_merge($tbBounces, $imapBounces);

        $existingTxtEmails = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $existingMap = [];
        foreach ($existingTxtEmails as $line) {
            $clean = strtolower(trim($line));
            if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
                $existingMap[$clean] = 'Manual / BlockEmail.txt';
            }
        }

        $appendHandle = fopen($txtFile, 'a');
        $newlyAddedToTxt = 0;
        foreach ($allDiscovered as $email => $source) {
            if (!isset($existingMap[$email])) {
                fwrite($appendHandle, $email . PHP_EOL);
                $existingMap[$email] = $source;
                $newlyAddedToTxt++;
            }
        }
        fclose($appendHandle);

        echo "   [+] Added {$newlyAddedToTxt} new 'Delivery Status Notification (Failure)' emails to {$txtFile}.\n";
        echo "   [*] Total emails queued in {$txtFile} for database blocking: " . count($existingMap) . "\n";

        // 4. Update or Insert into AgentsEmailTable with Statuss = '2' (Blocked)
        $archiveHandle = fopen($archiveFile, 'a');
        $csvExists = file_exists($csvReport);
        $csvHandle = fopen($csvReport, 'a');
        if (!$csvExists) {
            fprintf($csvHandle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($csvHandle, ['Timestamp', 'Bounced Email Address', 'Detection Source', 'Database Action']);
        }

        $checkStmt  = $this->db->prepare("SELECT Id, Statuss FROM AgentsEmailTable WHERE EmailId = ? LIMIT 1");
        $updateStmt = $this->db->prepare("UPDATE AgentsEmailTable SET Statuss = '2' WHERE EmailId = ?");
        $insertStmt = $this->db->prepare("INSERT INTO AgentsEmailTable (EmailId, SendDate, Statuss, SendedEmail) VALUES (?, NOW(), '2', 0)");

        $updatedCount  = 0;
        $insertedCount = 0;

        foreach ($existingMap as $email => $source) {
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $row = $checkStmt->get_result()->fetch_assoc();

            $dbAction = 'Already Blocked (Statuss=2)';
            if ($row) {
                if ((string)$row['Statuss'] !== '2') {
                    $updateStmt->bind_param("s", $email);
                    $updateStmt->execute();
                    $updatedCount++;
                    $dbAction = 'Updated to Blocked (Statuss=2)';
                    echo "       [BLOCKED-UPDATE] {$email} ({$source})\n";
                }
            } else {
                $insertStmt->bind_param("s", $email);
                $insertStmt->execute();
                $insertedCount++;
                $dbAction = 'Inserted as Blocked (Statuss=2)';
                echo "       [BLOCKED-INSERT] {$email} ({$source})\n";
            }

            $timestamp = date('Y-m-d H:i:s');
            fwrite($archiveHandle, "{$timestamp} | {$email} | {$source} | {$dbAction}" . PHP_EOL);
            fputcsv($csvHandle, [$timestamp, $email, $source, $dbAction]);
        }

        $checkStmt->close();
        $updateStmt->close();
        $insertStmt->close();
        fclose($archiveHandle);
        fclose($csvHandle);

        // 5. Empty BlockEmail.txt after archiving & syncing to DB
        file_put_contents($txtFile, '');

        echo "\nSUCCESS: Bounce scan, DB block & Thunderbird/IMAP bounce deletion complete!\n";
        echo "   -> Thunderbird Bounces Found        : " . count($tbBounces) . "\n";
        echo "   -> Live IMAP Bounces Found          : " . count($imapBounces) . "\n";
        echo "   -> Bounce Emails Deleted (Local TB) : {$this->totalDeletedLocal}\n";
        echo "   -> Bounce Emails Deleted (IMAP)     : {$this->totalDeletedImap}\n";
        echo "   -> DB Records Updated (Statuss=2)   : {$updatedCount}\n";
        echo "   -> New Blocked Records Inserted     : {$insertedCount}\n";
        echo "   -> Archive Log : {$archiveFile}\n";
        echo "   -> CSV Report  : {$csvReport}\n";

        return [
            'thunderbird_found' => count($tbBounces),
            'imap_found'        => count($imapBounces),
            'deleted_local'     => $this->totalDeletedLocal,
            'deleted_imap'      => $this->totalDeletedImap,
            'db_updated'        => $updatedCount,
            'db_inserted'       => $insertedCount,
            'report_csv'        => $csvReport
        ];
    }
}