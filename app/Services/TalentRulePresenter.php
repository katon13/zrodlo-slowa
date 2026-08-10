<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Operatorski opis reguł Talentu. Klucze activity_type pozostają kontraktem
 * zapisu i naliczania, ale nie są tekstem interfejsu administracyjnego.
 */
final class TalentRulePresenter
{
    /** @var array<string,array{readiness:string,trigger:string,locked:bool}> */
    private const OPERATIONS = [
        'registration_bonus' => ['readiness' => 'GOTOWE · TRWAŁY JOB', 'trigger' => 'Job z referencją user_registration:{user_id} jest zapisywany atomowo z utworzeniem pierwszego konta.', 'locked' => false],
        'day_visit_bonus' => ['readiness' => 'GOTOWE · DOWÓD OBECNOŚCI', 'trigger' => 'Widoczna karta, heartbeat, dzień biznesowy i trwały job.', 'locked' => false],
        'login_bonus' => ['readiness' => 'HISTORYCZNE · BEZ WYZWALACZA', 'trigger' => 'Samo logowanie nie jest nagradzane; używana jest aktywna wizyta dzienna.', 'locked' => true],
        'article_read_bonus' => ['readiness' => 'GOTOWE · CZAS I POSTĘP', 'trigger' => 'Jednorazowy proof w Valkey, widoczna karta, minimalny czas i postęp.', 'locked' => false],
        'response_publication_bonus' => ['readiness' => 'GOTOWE · NAGRODA I KAUCJA W LEDGERZE', 'trigger' => 'Pierwsze wysłanie zapisuje i pobiera kaucję; publikacja zwraca kaucję oraz zapisuje nagrodę TT do joba.', 'locked' => false],
        'share_bonus' => ['readiness' => 'WSTRZYMANE · BRAK POTWIERDZENIA', 'trigger' => 'Obecny sygnał użytkownika nie dowodzi skutecznego udostępnienia.', 'locked' => true],
        'link_click_bonus' => ['readiness' => 'WSTRZYMANE · SYGNAŁ WŁASNY', 'trigger' => 'Brak zaufanego celu i dowodu kliknięcia poza ogólnym formularzem.', 'locked' => true],
        'like_bonus' => ['readiness' => 'WSTRZYMANE · BRAK MODELU', 'trigger' => 'Serwis nie ma zamkniętego, unikalnego modelu polubień.', 'locked' => true],
        'bug_report_bonus' => ['readiness' => 'WSTRZYMANE · WYMAGA AKCEPTACJI', 'trigger' => 'Nagroda wymaga deduplikacji i ręcznej akceptacji prawdziwego błędu.', 'locked' => true],
        'survey_reward' => ['readiness' => 'GOTOWE · PLN ODDZIELONE OD TT', 'trigger' => 'Kompletna odpowiedź uruchamia job: zapisane w ankiecie PLN oraz TT z aktywnej reguły Talent. Nieaktywna reguła oznacza 0 TT, ale nie blokuje należnych PLN.', 'locked' => false],
        'sponsored_article_read_bonus' => ['readiness' => 'WSTRZYMANE · DOWÓD KAMPANII', 'trigger' => 'Wymaga czasu czytania, budżetu kampanii i odpornego dowodu zdarzenia.', 'locked' => true],
        'ad_view_reward' => ['readiness' => 'GOTOWE · DOWÓD CZASU I WIDOCZNOŚCI', 'trigger' => 'Jednorazowy dowód serwera, minimalny czas ekspozycji, aktywna kampania, budżet i FraudGuard.', 'locked' => false],
        'ad_click_reward' => ['readiness' => 'GOTOWE · PRZEKIEROWANIE SERWERA', 'trigger' => 'Serwer zapisuje właściwy cel kampanii, deduplikuje kliknięcie i dopiero potem wykonuje przekierowanie.', 'locked' => false],
        'newsletter_open_reward' => ['readiness' => 'WSTRZYMANE · BRAK WEBHOOKA', 'trigger' => 'Otworzenie musi pochodzić z wiarygodnego zdarzenia dostawcy poczty.', 'locked' => true],
        'ppv_reward' => ['readiness' => 'WSTRZYMANE · UDZIAŁ NIEPOTWIERDZONY', 'trigger' => 'Wymaga opłaconego dostępu i minimalnego, potwierdzonego udziału.', 'locked' => true],
        'live_event_reward' => ['readiness' => 'WSTRZYMANE · CZAS OBECNOŚCI', 'trigger' => 'Wymaga minimalnego czasu obecności i odporności na wiele kart.', 'locked' => true],
    ];

    /** @var array<string,array{title:string,description:string,icon:string}> */
    private const GROUPS = [
        'presence' => [
            'title' => 'Start i aktywna obecność',
            'description' => 'Nagrody związane z utworzeniem konta i potwierdzoną obecnością zalogowanego użytkownika.',
            'icon' => 'sun',
        ],
        'community' => [
            'title' => 'Czytanie i społeczność',
            'description' => 'Nagrody za rzeczywistą aktywność przy treściach oraz działania społecznościowe.',
            'icon' => 'article',
        ],
        'campaigns' => [
            'title' => 'Ankiety i kampanie',
            'description' => 'Zasady wynagradzania udziału w badaniach, newsletterach i kampaniach partnerów.',
            'icon' => 'survey',
        ],
        'events' => [
            'title' => 'Materiały płatne i wydarzenia',
            'description' => 'Nagrody powiązane z materiałami PPV i potwierdzonym udziałem w transmisjach na żywo.',
            'icon' => 'video',
        ],
        'other' => [
            'title' => 'Pozostałe działania',
            'description' => 'Reguły dodatkowe rozpoznane przez system nagród.',
            'icon' => 'points',
        ],
    ];

    /** @var array<string,array{title:string,description:string,group:string,icon:string,badge?:string,tone?:string}> */
    private const RULES = [
        'registration_bonus' => [
            'title' => 'Założenie konta',
            'description' => 'Przyznawana jeden raz po prawidłowym utworzeniu konta użytkownika.',
            'group' => 'presence',
            'icon' => 'registration',
            'badge' => 'Jednorazowa',
        ],
        'day_visit_bonus' => [
            'title' => 'Aktywna wizyta dzienna',
            'description' => 'Uruchamiana dopiero po potwierdzeniu aktywnej obecności zalogowanego użytkownika. Samo otwarcie strony nie wystarcza.',
            'group' => 'presence',
            'icon' => 'sun',
            'badge' => 'Kontrola obecności',
        ],
        'login_bonus' => [
            'title' => 'Logowanie — reguła historyczna',
            'description' => 'Zachowana dla zgodności ze starszą wersją. Obecny mechanizm nie nagradza samego logowania; używa aktywnej wizyty dziennej.',
            'group' => 'presence',
            'icon' => 'login',
            'badge' => 'Nie używać w nowym modelu',
            'tone' => 'warning',
        ],
        'article_read_bonus' => [
            'title' => 'Przeczytanie artykułu',
            'description' => 'Przyznawana po weryfikacji czasu czytania i postępu w treści, nie za samo wejście na artykuł.',
            'group' => 'community',
            'icon' => 'article',
            'badge' => 'Weryfikacja czytania',
        ],
        'response_publication_bonus' => [
            'title' => 'Opublikowana opinia lub polemika',
            'description' => 'Po pierwszym wysłaniu system zapisuje i pobiera ustawioną kaucję TT. Publikacja zwraca kaucję i uruchamia nagrodę; odrzucenie przekazuje kaucję serwisowi. Poprawka tej samej polemiki nie pobiera jej ponownie.',
            'group' => 'community',
            'icon' => 'article',
            'badge' => 'TT po publikacji · kaucja 1 raz',
        ],
        'share_bonus' => [
            'title' => 'Udostępnienie treści',
            'description' => 'Przyznawana za zarejestrowane udostępnienie konkretnej treści.',
            'group' => 'community',
            'icon' => 'share',
        ],
        'link_click_bonus' => [
            'title' => 'Kliknięcie promowanego linku',
            'description' => 'Przyznawana za zarejestrowane kliknięcie w obsługiwany link.',
            'group' => 'community',
            'icon' => 'cursor',
        ],
        'like_bonus' => [
            'title' => 'Polubienie treści',
            'description' => 'Przyznawana za polubienie przypisane do użytkownika i konkretnej treści.',
            'group' => 'community',
            'icon' => 'like',
        ],
        'bug_report_bonus' => [
            'title' => 'Zgłoszenie błędu',
            'description' => 'Nagroda za zarejestrowane zgłoszenie pomagające poprawić działanie serwisu.',
            'group' => 'community',
            'icon' => 'bug',
        ],
        'survey_reward' => [
            'title' => 'Udział w ankiecie',
            'description' => 'TT przyznawane po zapisaniu kompletnej odpowiedzi. Kwota PLN jest snapshotowana i kontrolowana oddzielnie przez konkretną ankietę.',
            'group' => 'campaigns',
            'icon' => 'survey',
            'badge' => 'TT z Talent · PLN z ankiety',
        ],
        'sponsored_article_read_bonus' => [
            'title' => 'Przeczytanie treści sponsorowanej',
            'description' => 'Przyznawana za potwierdzone przeczytanie materiału w ramach aktywnej kampanii.',
            'group' => 'campaigns',
            'icon' => 'article',
        ],
        'ad_view_reward' => [
            'title' => 'Obejrzenie materiału reklamowego',
            'description' => 'TT są snapshotowane po potwierdzeniu minimalnego czasu, widocznej sesji, aktywnego budżetu i dodatniej marży kampanii.',
            'group' => 'campaigns',
            'icon' => 'eye',
            'badge' => 'Koszt PLN · nagroda TT',
        ],
        'ad_click_reward' => [
            'title' => 'Kliknięcie reklamy',
            'description' => 'TT są snapshotowane przy bezpiecznym przekierowaniu przez serwer. Duplikat nie zużywa budżetu i nie daje kolejnej nagrody.',
            'group' => 'campaigns',
            'icon' => 'cursor',
            'badge' => 'Koszt PLN · nagroda TT',
        ],
        'newsletter_open_reward' => [
            'title' => 'Otwarcie wiadomości od redakcji',
            'description' => 'Przyznawana, gdy system pocztowy przekaże potwierdzone otwarcie obsługiwanej wiadomości.',
            'group' => 'campaigns',
            'icon' => 'mail',
        ],
        'ppv_reward' => [
            'title' => 'Udział w materiale PPV',
            'description' => 'Przyznawana po zarejestrowaniu udziału użytkownika w materiale płatnym.',
            'group' => 'events',
            'icon' => 'video',
        ],
        'live_event_reward' => [
            'title' => 'Udział w wydarzeniu na żywo',
            'description' => 'Przyznawana za potwierdzone dołączenie do aktywnego wydarzenia live.',
            'group' => 'events',
            'icon' => 'video',
        ],
    ];

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{key:string,title:string,description:string,icon:string,rules:list<array<string,mixed>>}>
     */
    public static function groups(array $rows): array
    {
        $grouped = [];
        foreach (self::GROUPS as $key => $group) {
            $grouped[$key] = $group + ['key' => $key, 'rules' => []];
        }

        foreach ($rows as $row) {
            $type = ActivityUiHelper::normalizeType((string)($row['activity_type'] ?? ''));
            $definition = self::RULES[$type] ?? self::fallbackDefinition($type);
            $operation = self::OPERATIONS[$type] ?? [
                'readiness' => 'NIEZWERYFIKOWANE',
                'trigger' => 'Brak opisanego, zaufanego punktu wyzwolenia.',
                'locked' => true,
            ];
            $group = isset($grouped[$definition['group']]) ? $definition['group'] : 'other';
            $grouped[$group]['rules'][] = $row + [
                'operator_title' => $definition['title'],
                'operator_description' => $definition['description'],
                'operator_icon' => $definition['icon'],
                'operator_badge' => $definition['badge'] ?? null,
                'operator_tone' => $definition['tone'] ?? 'default',
                'operator_readiness' => $operation['readiness'],
                'operator_trigger' => $operation['trigger'],
                'operator_activation_locked' => $operation['locked'],
            ];
        }

        return array_values(array_filter(
            $grouped,
            static fn(array $group): bool => $group['rules'] !== []
        ));
    }

    /** @return array{title:string,description:string,group:string,icon:string} */
    private static function fallbackDefinition(string $type): array
    {
        $label = ActivityUiHelper::getLabel($type, 'pl');
        if ($label === '' || $label === $type) {
            $label = ucfirst(str_replace('_', ' ', preg_replace('/_(bonus|reward)$/', '', $type) ?? $type));
        }

        return [
            'title' => $label,
            'description' => 'Dodatkowa reguła aktywności obsługiwana przez system nagród.',
            'group' => 'other',
            'icon' => ActivityUiHelper::getIconName($type),
        ];
    }
}
