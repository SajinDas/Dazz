<?php
namespace Dazz\Legacy;

class Config {
    const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const DB_HOST     = '127.0.0.1';
    const DB_USER     = 'root';
    const DB_PASS     = 'M!cr0s0ft';
    const DB_NAME     = 'dazz';

    // Optional: Custom Thunderbird Profiles path on Windows.
    // Leave empty ('') to auto-detect %APPDATA%\Thunderbird\Profiles and %LOCALAPPDATA%\Thunderbird\Profiles
    const THUNDERBIRD_PROFILE_PATH = '';

    // Default daily sending limit per sender account
    const DAILY_SENDER_LIMIT = 400;

    // Default sender priority order
    const DEFAULT_SENDERS = [5, 4, 3, 2, 1, 6];

    // Supported European countries & portal metadata
    const COUNTRIES = [
        'lv' => [
            'name'        => 'Latvia',
            'lang'        => 'lv',
            'eures_code'  => 'lv',
            'pes_name'    => 'NVA (Nodarbinātības valsts aģentūra)',
            'pes_url'     => 'https://cvvp.nva.gov.lv/#/pub/vakances/saraksts',
            'link_filter' => '/pub/vakances/',
            'reveal_btns' => ['Pieteikties', 'Rādīt']
        ],
        'sk' => [
            'name'        => 'Slovakia',
            'lang'        => 'sk',
            'eures_code'  => 'sk',
            'pes_name'    => 'Služby zamestnanosti / ISTP and Profesia.sk',
            'pes_url'     => 'https://www.sluzbyzamestnanosti.gov.sk/pracovne-ponuky?pageNr=1&pageSize=30&zdrojPonuky=VPM&pozadovanaZnalostSk=false',
            'link_filter' => '/pracovne-ponuky/',
            'reveal_btns' => ['Zobraziť', 'Kontakt']
        ],
        'cz' => [
            'name'        => 'Czechia',
            'lang'        => 'cs',
            'eures_code'  => 'cz',
            'pes_name'    => 'MPSV Foreigner Vacancies and Úřad práce ČR',
            'pes_url'     => 'https://mpsv.gov.cz/vyhledavani-volnych-pracovnich-mist-pro-cizince',
            'alt_url'     => 'https://up.gov.cz/volna-mista-v-cr',
            'link_filter' => 'volna-mista',
            'reveal_btns' => ['Detail', 'Kontakt']
        ],
        'si' => [
            'name'        => 'Slovenia',
            'lang'        => 'sl',
            'eures_code'  => 'si',
            'pes_name'    => 'ZRSZ (Zavod Republike Slovenije za zaposlovanje)',
            'pes_url'     => 'https://www.ess.gov.si/iskalci-zaposlitve/iskanje-zaposlitve/iskanje-dela/',
            'link_filter' => 'iskanje-dela',
            'reveal_btns' => ['Več', 'Kontakt', 'Prijava']
        ],
        'hr' => [
            'name'        => 'Croatia',
            'lang'        => 'hr',
            'eures_code'  => 'hr',
            'pes_name'    => 'HZZ Burza rada',
            'pes_url'     => 'https://burzarada.hzz.hr/Posloprimac_RadnaMjesta.aspx',
            'link_filter' => 'RadnoMjesto_Detalji',
            'reveal_btns' => ['Detalji', 'Kontakt poslodavca']
        ],
        'lt' => [
            'name'        => 'Lithuania',
            'lang'        => 'lt',
            'eures_code'  => 'lt',
            'pes_name'    => 'Užimtumo tarnyba (UŽT)',
            'pes_url'     => 'https://uzt.lt/darbo-ieskantiems/ieskau-darbo',
            'link_filter' => 'laisvos-darbo-vietos',
            'reveal_btns' => ['Kontaktai', 'Rodyti', 'Kreiptis']
        ],
        'ee' => [
            'name'        => 'Estonia',
            'lang'        => 'et',
            'eures_code'  => 'ee',
            'pes_name'    => 'Eesti Töötukassa',
            'pes_url'     => 'https://www.tootukassa.ee/et/toopakkumised',
            'link_filter' => '/toopakkumised/',
            'reveal_btns' => ['Kandideeri', 'Näita kontakte']
        ],
        'pl' => [
            'name'        => 'Poland',
            'lang'        => 'pl',
            'eures_code'  => 'pl',
            'pes_name'    => 'ePraca - Centralna Baza Ofert Pracy (CBOP)',
            'pes_url'     => 'https://oferty.praca.gov.pl/portal/lista-ofert',
            'link_filter' => 'szczegoly-oferty',
            'reveal_btns' => ['Szczegóły', 'Dane kontaktowe']
        ],
        'ro' => [
            'name'        => 'Romania',
            'lang'        => 'ro',
            'eures_code'  => 'ro',
            'pes_name'    => 'Mediere ANOFM',
            'pes_url'     => 'https://mediere.anofm.ro/app/module/mediere/jobs',
            'link_filter' => '/mediere/jobs',
            'reveal_btns' => ['Detalii', 'Contact']
        ],
        'hu' => [
            'name'        => 'Hungary',
            'lang'        => 'hu',
            'eures_code'  => 'hu',
            'pes_name'    => 'Virtuális Munkaerőpiac Portál (VMP)',
            'pes_url'     => 'https://vmp.munka.hu/allaskeresoknek',
            'link_filter' => 'allasajanlat',
            'reveal_btns' => ['Részletek', 'Kapcsolat']
        ],
        'mt' => [
            'name'        => 'Malta',
            'lang'        => 'en',
            'eures_code'  => 'mt',
            'pes_name'    => 'Jobsplus Malta',
            'pes_url'     => 'https://jobsplus.gov.mt/',
            'link_filter' => 'vacancy',
            'reveal_btns' => ['Apply', 'View Details', 'Contact']
        ],
        'de' => [
            'name'        => 'Germany',
            'lang'        => 'de',
            'eures_code'  => 'de',
            'pes_name'    => 'Bundesagentur für Arbeit Jobsuche',
            'pes_url'     => 'https://www.arbeitsagentur.de/jobsuche/',
            'link_filter' => '/jobsuche/suche',
            'reveal_btns' => ['Zur Bewerbung', 'Kontaktdaten']
        ]
    ];

    public static function getDB() {
        \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        echo "=== ATTEMPTING DB CONNECTION ===\n";

        try {
            $conn = new \mysqli(self::DB_HOST, self::DB_USER, self::DB_PASS, self::DB_NAME);
            $conn->set_charset("utf8mb4");
            echo "=== DB CONNECTION ESTABLISHED ===\n";
            return $conn;
        } catch (\mysqli_sql_exception $e) {
            error_log("Connection failed: " . $e->getMessage());
            die("=== DB CONNECTION FAILED: " . $e->getMessage() . " ===\n");
        }
    }
}
