<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Operatorski opis reguł Talentu. Klucze activity_type pozostają kontraktem
 * zapisu i naliczania, ale nie są tekstem interfejsu administracyjnego.
 */
final class TalentRulePresenter
{
    private const VISIBLE_TYPES = [
        'registration_bonus','day_visit_bonus','article_read_bonus','response_publication_bonus',
        'bug_report_bonus','survey_reward','ad_view_reward','ad_click_reward',
    ];
    /** @var array<string,array{readiness:string,trigger:string,locked:bool}> */
    private const OPERATIONS = [
        'registration_bonus' => ['readiness' => 'DZIAŁA', 'trigger' => 'Nagroda trafia do użytkownika jeden raz po prawidłowym założeniu konta.', 'locked' => false],
        'day_visit_bonus' => ['readiness' => 'DZIAŁA', 'trigger' => 'Nagroda trafia raz dziennie po potwierdzeniu aktywnej wizyty.', 'locked' => false],
        'login_bonus' => ['readiness' => 'HISTORYCZNE · BEZ WYZWALACZA', 'trigger' => 'Samo logowanie nie jest nagradzane; używana jest aktywna wizyta dzienna.', 'locked' => true],
        'article_read_bonus' => ['readiness' => 'DZIAŁA', 'trigger' => 'Nagroda trafia po rzeczywistym przeczytaniu tekstu.', 'locked' => false],
        'response_publication_bonus' => ['readiness' => 'DZIAŁA', 'trigger' => 'Kaucja jest pobierana przy wysłaniu, a po publikacji wraca razem z nagrodą.', 'locked' => false],
        'share_bonus' => ['readiness' => 'WSTRZYMANE · BRAK POTWIERDZENIA', 'trigger' => 'Obecny sygnał użytkownika nie dowodzi skutecznego udostępnienia.', 'locked' => true],
        'link_click_bonus' => ['readiness' => 'WSTRZYMANE · SYGNAŁ WŁASNY', 'trigger' => 'Brak zaufanego celu i dowodu kliknięcia poza ogólnym formularzem.', 'locked' => true],
        'like_bonus' => ['readiness' => 'WSTRZYMANE · BRAK MODELU', 'trigger' => 'Serwis nie ma zamkniętego, unikalnego modelu polubień.', 'locked' => true],
        'bug_report_bonus' => ['readiness' => 'DZIAŁA', 'trigger' => 'Nagroda trafia tylko za prawdziwy błąd zaakceptowany przez redakcję.', 'locked' => false],
        'survey_reward' => ['readiness' => 'DZIAŁA', 'trigger' => 'Nagroda TT trafia po zapisaniu kompletnej odpowiedzi.', 'locked' => false],
        'sponsored_article_read_bonus' => ['readiness' => 'WSTRZYMANE · DOWÓD KAMPANII', 'trigger' => 'Wymaga czasu czytania, budżetu kampanii i odpornego dowodu zdarzenia.', 'locked' => true],
        'ad_view_reward' => ['readiness' => 'DZIAŁA', 'trigger' => 'Nagroda trafia po obejrzeniu filmu przez czas ustawiony w kampanii.', 'locked' => false],
        'ad_click_reward' => ['readiness' => 'DZIAŁA', 'trigger' => 'Nagroda trafia po potwierdzonym przejściu z banera.', 'locked' => false],
        'newsletter_open_reward' => ['readiness' => 'WSTRZYMANE · BRAK WEBHOOKA', 'trigger' => 'Otworzenie musi pochodzić z wiarygodnego zdarzenia dostawcy poczty.', 'locked' => true],
        'ppv_reward' => ['readiness' => 'WSTRZYMANE · UDZIAŁ NIEPOTWIERDZONY', 'trigger' => 'Wymaga opłaconego dostępu i minimalnego, potwierdzonego udziału.', 'locked' => true],
        'live_event_reward' => ['readiness' => 'WSTRZYMANE · CZAS OBECNOŚCI', 'trigger' => 'Wymaga minimalnego czasu obecności i odporności na wiele kart.', 'locked' => true],
    ];

    /** @var array<string,array{title:string,description:string,icon:string}> */
    private const GROUPS = [
        'presence' => [
            'title' => 'Start i aktywna obecność',
            'description' => 'Proste nagrody za rozpoczęcie i regularne korzystanie z serwisu.',
            'icon' => 'sun',
        ],
        'community' => [
            'title' => 'Czytanie i społeczność',
            'description' => 'Nagrody za czytanie, publikacje i pomoc w poprawianiu serwisu.',
            'icon' => 'article',
        ],
        'campaigns' => [
            'title' => 'Ankiety i kampanie',
            'description' => 'Nagrody TT za potwierdzone działania w kampaniach i ankietach.',
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
            'description' => 'Raz dziennie za prawdziwą, aktywną wizytę zalogowanego użytkownika.',
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
            'description' => 'Za przeczytanie tekstu przez wymagany czas i dotarcie do odpowiedniej części artykułu.',
            'group' => 'community',
            'icon' => 'article',
            'badge' => 'Weryfikacja czytania',
        ],
        'response_publication_bonus' => [
            'title' => 'Opublikowana opinia lub polemika',
            'description' => 'Kaucja jest pobierana raz przy wysłaniu. Po publikacji wraca, a użytkownik otrzymuje nagrodę TT.',
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
            'description' => 'Za prawdziwy błąd sprawdzony i zaakceptowany przez redakcję. Samo wysłanie nie daje TT.',
            'group' => 'community',
            'icon' => 'bug',
        ],
        'survey_reward' => [
            'title' => 'Udział w ankiecie',
            'description' => 'Za prawidłowe ukończenie ankiety. Budżet reklamodawcy pozostaje w przypiętej kampanii.',
            'group' => 'campaigns',
            'icon' => 'survey',
            'badge' => 'TT z Programu Talent',
        ],
        'sponsored_article_read_bonus' => [
            'title' => 'Przeczytanie treści sponsorowanej',
            'description' => 'Przyznawana za potwierdzone przeczytanie materiału w ramach aktywnej kampanii.',
            'group' => 'campaigns',
            'icon' => 'article',
        ],
        'ad_view_reward' => [
            'title' => 'Obejrzenie materiału reklamowego',
            'description' => 'Za obejrzenie filmu przez czas ustawiony w aktywnej kampanii.',
            'group' => 'campaigns',
            'icon' => 'eye',
            'badge' => 'Koszt PLN · nagroda TT',
        ],
        'ad_click_reward' => [
            'title' => 'Kliknięcie reklamy',
            'description' => 'Za potwierdzone przejście do strony reklamodawcy. Powtórzenie nie daje kolejnej nagrody.',
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
            if (!in_array($type, self::VISIBLE_TYPES, true)) {
                continue;
            }
            $definition = self::RULES[$type];
            $operation = self::OPERATIONS[$type];
            $group = $definition['group'];
            $badge = array_key_exists('badge', $definition) ? (string)$definition['badge'] : null;
            $grouped[$group]['rules'][] = $row + [
                'operator_title' => $definition['title'],
                'operator_description' => $definition['description'],
                'operator_icon' => $definition['icon'],
                'operator_badge' => $badge,
                'operator_tone' => 'default',
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

}
