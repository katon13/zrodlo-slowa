<?php
declare(strict_types=1);

namespace App\Services;

final class ArticleLabelPresenter
{
    /**
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'Hot News' => [
            'pl' => 'Pilne',
            'en' => 'Hot News',
            'de' => 'Eilmeldung',
            'fr' => 'Actualité urgente',
            'it' => 'Ultime notizie',
            'es' => 'Última hora',
        ],
        'Important' => [
            'pl' => 'Ważne',
            'en' => 'Important',
            'de' => 'Wichtig',
            'fr' => 'Important',
            'it' => 'Importante',
            'es' => 'Importante',
        ],
        'Discussion' => [
            'pl' => 'Dyskusja',
            'en' => 'Discussion',
            'de' => 'Diskussion',
            'fr' => 'Discussion',
            'it' => 'Discussione',
            'es' => 'Debate',
        ],
        'Opinion' => [
            'pl' => 'Opinia',
            'en' => 'Opinion',
            'de' => 'Meinung',
            'fr' => 'Opinion',
            'it' => 'Opinione',
            'es' => 'Opinión',
        ],
        'Analysis' => [
            'pl' => 'Analiza',
            'en' => 'Analysis',
            'de' => 'Analyse',
            'fr' => 'Analyse',
            'it' => 'Analisi',
            'es' => 'Análisis',
        ],
        'Exclusive' => [
            'pl' => 'Ekskluzywne',
            'en' => 'Exclusive',
            'de' => 'Exklusiv',
            'fr' => 'Exclusif',
            'it' => 'Esclusivo',
            'es' => 'Exclusivo',
        ],
        'Interview' => [
            'pl' => 'Wywiad',
            'en' => 'Interview',
            'de' => 'Interview',
            'fr' => 'Entretien',
            'it' => 'Intervista',
            'es' => 'Entrevista',
        ],
        'Reportage' => [
            'pl' => 'Reportaż',
            'en' => 'Reportage',
            'de' => 'Reportage',
            'fr' => 'Reportage',
            'it' => 'Reportage',
            'es' => 'Reportaje',
        ],
        'Sponsored' => [
            'pl' => 'Sponsorowane',
            'en' => 'Sponsored',
            'de' => 'Gesponsert',
            'fr' => 'Sponsorisé',
            'it' => 'Sponsorizzato',
            'es' => 'Patrocinado',
        ],
        'Breaking' => [
            'pl' => 'Ostatnia chwila',
            'en' => 'Breaking',
            'de' => 'Aktuell',
            'fr' => 'Dernière minute',
            'it' => 'Ultim’ora',
            'es' => 'Última hora',
        ],
        "Editor's Pick" => [
            'pl' => 'Wybór redakcji',
            'en' => "Editor's Pick",
            'de' => 'Empfehlung der Redaktion',
            'fr' => 'Choix de la rédaction',
            'it' => 'Scelta della redazione',
            'es' => 'Selección de la redacción',
        ],
    ];

    public static function display(?string $label, string $language = 'pl'): ?string
    {
        $label = trim((string)$label);
        if ($label === '') {
            return null;
        }

        $language = strtolower(trim($language));
        return self::LABELS[$label][$language]
            ?? self::LABELS[$label]['en']
            ?? $label;
    }
}
