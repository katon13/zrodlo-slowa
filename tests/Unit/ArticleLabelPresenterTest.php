<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ArticleLabelPresenter;
use PHPUnit\Framework\TestCase;

final class ArticleLabelPresenterTest extends TestCase
{
    public function testKnownLabelIsLocalized(): void
    {
        self::assertSame('Pilne', ArticleLabelPresenter::display('Hot News', 'pl'));
        self::assertSame('Eilmeldung', ArticleLabelPresenter::display('Hot News', 'de'));
        self::assertSame('Tekst sponsorowany', ArticleLabelPresenter::display('Sponsored', 'pl'));
        self::assertSame('Sponsored content', ArticleLabelPresenter::display('Sponsored', 'en'));
    }

    public function testUnknownLabelIsPreserved(): void
    {
        self::assertSame('Własna etykieta', ArticleLabelPresenter::display('Własna etykieta', 'pl'));
        self::assertNull(ArticleLabelPresenter::display(''));
    }
}
