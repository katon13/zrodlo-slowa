# AUDYT 02 — Mapa wejść systemu (Trasy)

## 1. Tabela tras i uprawnień

| Metoda | URL | Kontroler | Metoda | Rola użytkownika | CSRF | Moduł | Ryzyko |
| --- | --- | --- | --- | --- | --- | --- | --- |
| GET | `/` | HomeController | index | Publiczny | Nie | Home | Niskie |
| GET | `/jak-zarabiac` | HomeController | economy | Publiczny | Nie | Home | Niskie |
| GET | `/register` | AuthController | showRegister | Publiczny | Nie | Auth | Niskie |
| POST | `/register` | AuthController | register | Publiczny | Tak | Auth | Średnie |
| GET | `/login` | AuthController | showLogin | Publiczny | Nie | Auth | Niskie |
| POST | `/login` | AuthController | login | Publiczny | Tak | Auth | Średnie |
| POST | `/logout` | AuthController | logout | Zalogowany | Tak | Auth | Niskie |
| GET | `/sitemap.xml` | SitemapController | index | Publiczny | Nie | SEO | Niskie |
| GET | `/articles` | ArticleController | index | Publiczny | Nie | Articles | Niskie |
| GET | `/article` | ArticleController | show | Publiczny | Nie | Articles | Niskie |
| POST | `/article/support` | ArticleController | support | Zalogowany | Tak | Articles | Średnie |
| POST | `/article/buy` | ArticleController | buy | Zalogowany | Tak | Articles | Wysokie (Finanse) |
| GET | `/surveys` | SurveyController | index | Zalogowany | Nie | Surveys | Niskie |
| POST | `/survey/submit` | SurveyController | submit | Zalogowany | Tak | Surveys | Średnie |
| GET | `/campaigns` | CampaignController | index | Zalogowany | Nie | Campaigns | Niskie |
| POST | `/campaign/view` | CampaignController | viewAd | Zalogowany | Tak | Campaigns | Średnie |
| POST | `/activity/record` | ActivityController | record | Zalogowany | Tak | Activity | Średnie |
| GET | `/author` | AuthorController | dashboard | Autor | Nie | Authors | Niskie |
| POST | `/author/articles` | AuthorController | storeArticle | Autor | Tak | Authors | Średnie |
| GET | `/reader` | ReaderController | dashboard | Czytelnik | Nie | Reader | Niskie |
| GET | `/wallet` | WalletController | show | Zalogowany | Nie | Wallet | Średnie |
| POST | `/wallet/topup` | WalletTopupController | create | Zalogowany | Tak | Wallet | Wysokie |
| POST | `/stripe/webhook` | StripeWebhookController | handle | Publiczny | Nie* | Payments | Krytyczne |
| POST | `/wallet/transfer/talent-to-pln` | WalletTransferController | talentToPln | Zalogowany | Tak | Wallet | Wysokie |
| POST | `/wallet/payout-request` | WalletController | requestPayout | Zalogowany | Tak | Wallet | Wysokie |
| GET | `/admin` | AdminController | dashboard | Admin | Nie | Admin | Niskie |
| GET | `/admin/articles` | AdminController | articles | Admin / Chief Editor | Nie | Admin | Niskie |
| GET | `/admin/editorial` | AdminController | editorial | Admin / Editor / Publisher | Nie | Admin | Niskie |
| POST | `/admin/articles/status` | AdminController | setArticleStatus | Admin / Chief Editor | Tak | Admin | Średnie |
| POST | `/admin/articles/valuation` | AdminController | setArticleValuation | Admin / Moderator | Tak | Admin | Wysokie |
| GET | `/admin/payouts` | AdminController | payouts | Admin / Accountant | Nie | Payouts | Wysokie |
| GET | `/admin/ai` | AiAdminController | index | Admin | Nie | AI | Średnie |
| GET | `/admin/finance` | FinanceController | report | Admin / Accountant | Nie | Finance | Średnie |

\* `StripeWebhookController` omija CSRF, ale musi weryfikować podpis Stripe (do sprawdzenia w Etapie 11).

## 2. Podsumowanie zabezpieczeń wejść

- **CSRF**: Globalnie wymuszany dla wszystkich żądań `POST` przez `Router` (wywołanie `verify_csrf()`).
- **Autoryzacja**: Realizowana w kontrolerach przez metody `requireAuth`, `requireAdmin`, `requireAdminOrRoles`.
- **Walidacja**: Każda metoda kontrolera powinna walidować dane z `$_POST` i `$_GET`.
- **Publiczny dostęp**: Ograniczony do strony głównej, logowania, rejestracji, publicznych artykułów, sitemapy i webhooków.
