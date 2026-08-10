#!/usr/bin/env python3
"""Move literal HTML UI copy from PHP views into JSON translation catalogs.

The script intentionally touches only plain HTML text nodes and fixed human-facing
attributes. PHP expressions, JavaScript and CSS are left for a manual pass.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import pathlib
import re
import subprocess
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request


ROOT = pathlib.Path(__file__).resolve().parents[1]
VIEWS = ROOT / "views"
PUBLIC_CATALOG = ROOT / "resources" / "lang" / "public.json"
ADMIN_CATALOG = ROOT / "resources" / "lang" / "admin.json"
SAFETY_CATALOG = ROOT / "resources" / "lang" / "safety_fund.json"
LANGUAGES = ("pl", "en", "de", "fr", "it", "es")
EXPLICIT_POLISH = {
    "account.security.missing_prefix": "Brakuje: {items}.",
    "admin.ai.drafts_count": "{count} szkiców",
    "admin.ai.instructions_count": "{count} instrukcji roboczych",
    "admin.ai.status_cancelled": "anulowane",
    "admin.ai.status_planned": "zaplanowane",
    "admin.ai.status_queued": "w kolejce",
    "admin.ai.status_running": "w trakcie",
    "admin.bug_reports.reports_count": "{count} zgłoszeń",
    "admin.campaign_report.remaining_amount": "Pozostało {amount}",
    "admin.campaign_report.client": "Zleceniodawca",
    "admin.campaign_report.estimated_service_result": "Szacowany wynik serwisu: {amount}",
    "admin.campaign_report.no_order_number": "Bez numeru zlecenia",
    "admin.campaign_report.video_view": "Obejrzenie filmu",
    "admin.campaigns.create_type": "Utwórz: {type}",
    "admin.campaigns.edit_order": "Edycja zlecenia #{id}",
    "admin.campaigns.effect_price_and_reward": "{amount} za efekt · {points} TT",
    "admin.campaigns.effects_count": "{count} potwierdzonych efektów",
    "admin.campaigns.free_duplicates_count": "{count} powtórzeń bez kosztu",
    "admin.campaigns.new_order": "Nowe zlecenie",
    "admin.campaigns.of_confirmed_budget": "Z potwierdzonego budżetu {amount}",
    "admin.campaigns.order_confirmed": "Zlecenie potwierdzone",
    "admin.campaigns.reklamodawca_paci_za_efekt_ktory_potrafimy_potwierdzic_6218d494": "Reklamodawca płaci za efekt, który potrafimy potwierdzić.",
    "admin.campaigns.remaining_of": "Pozostało z {amount}",
    "admin.campaigns.user_reward_and_billing": "Użytkownik otrzymuje {points} TT z Programu Talent. Reklamodawca płaci wyłącznie za potwierdzony efekt w PLN.",
    "admin.categories.confirm_delete": "Na pewno?",
    "admin.categories.contains_articles": "Kategoria zawiera artykuły",
    "admin.categories.hidden": "UKRYTA",
    "admin.categories.in_menu": "W MENU",
    "admin.categories.name_in_language": "Nazwa {language}",
    "admin.common.attention": "UWAGA",
    "admin.common.id": "ID",
    "admin.common.items_count": "{count} pozycji",
    "admin.common.text_number": "Tekst #{id}",
    "admin.common.page_number": "Strona {page}",
    "admin.common.page_and_limit": "Strona {page} / limit {limit}",
    "admin.common.missing": "brak",
    "admin.common.no_data": "brak danych",
    "admin.common.ok": "OK",
    "admin.common.required": "wymagane",
    "admin.common.status_unknown": "stan nierozpoznany",
    "admin.dashboard.clear_cache_error": "Nie udało się odświeżyć pamięci podręcznej.",
    "admin.dashboard.clearing_cache": "Odświeżanie...",
    "admin.dashboard.event_batches_and_checks": "Serie zdarzeń: {events} · kontrole: {checks}",
    "admin.dashboard.events_count": "{count} zdarzeń",
    "admin.dashboard.last_signal": "Ostatni sygnał: {age}",
    "admin.dashboard.no_current_processing_signal": "Brak aktualnego sygnału procesu naliczania",
    "admin.dashboard.operator_limit": "Limit obsługi: {value}",
    "admin.dashboard.requires_attention": "WYMAGA KONTROLI",
    "admin.dashboard.rules_without_value": "Bez ustalonej wartości: {count}",
    "admin.dashboard.seconds_ago": "{seconds} s temu",
    "admin.editorial_edit.ai_draft": "szkic AI",
    "admin.editorial_edit.decision_save_error": "Nie udało się zapisać decyzji Wydawcy.",
    "admin.editorial_edit.decision_saved": "Decyzja została zapisana.",
    "admin.editorial_edit.deposit": "Kaucja",
    "admin.editorial_edit.deposit_forfeited": "zatrzymana przez serwis",
    "admin.editorial_edit.deposit_held": "pobrana",
    "admin.editorial_edit.deposit_not_collected": "jeszcze nie pobrana",
    "admin.editorial_edit.deposit_not_required": "niewymagana",
    "admin.editorial_edit.deposit_refunded": "zwrócona użytkownikowi",
    "admin.editorial_edit.incomplete": "niekompletne",
    "admin.editorial_edit.last_proofreading": "ostatnia korekta: {date}",
    "admin.editorial_edit.preview_language": "Podgląd {language}",
    "admin.editorial_edit.proofreading": "korekta",
    "admin.editorial_edit.publish": "OPUBLIKUJ",
    "admin.editorial_edit.response_heading": "OPINIA / POLEMIKA DO PUBLIKACJI #{id}",
    "admin.editorial_edit.reward_after_first_publication": "nagroda zostanie ustalona przy pierwszej publikacji",
    "admin.editorial_edit.reward_points_saved": "{points} TT zapisane przy publikacji",
    "admin.editorial_edit.reward_rule_inactive": "bez nagrody — reguła była nieaktywna przy publikacji",
    "admin.editorial_edit.talent_reward": "Nagroda Talent",
    "admin.editorial_list.status_change_error": "Nie udało się zmienić statusu.",
    "admin.finance_report.accrued_income_and_user_rewards": "naliczony przychód · nagrody użytkowników: {points} TT",
    "admin.finance_report.available_amount": "{amount} dostępne",
    "admin.finance_report.purchases_count": "{count} zakupów",
    "admin.financial_approvals.operation_number": "OPERACJA #{id}",
    "admin.proofreader_edit.correction_mark": "KOREKTA — {date}",
    "admin.ledger.deleted_user": "Usunięty użytkownik #{id}",
    "admin.payments.errors_count": "{count} błędów",
    "admin.payments.current_mode": "Tryb: {mode}",
    "admin.payments.received_events_count": "{count} odebranych zdarzeń",
    "admin.payments.talents_amount": "{points} TT",
    "admin.payments.transfers_count": "{count} transferów",
    "admin.role_panel.translation_incomplete": "{language}: niekompletne tłumaczenie",
    "admin.role_panel.translation_missing": "{language}: brak tłumaczenia",
    "admin.role_panel.translation_saved": "{language}: tłumaczenie zapisane ({status})",
    "admin.role_panel.blocked_until": "Zablokowany do: {date}",
    "admin.role_panel.original_text": "Oryginalny tekst: {language}",
    "admin.role_panel.revenue_split": "Autor {author}% / Serwis {service}% / Safety Fund {fund}%",
    "admin.roles.user_number": "Użytkownik #{id}",
    "admin.settings.intro": "Centrum ustawień ekonomii, Talentu i Snajpera Słowa. Tutaj zarządzasz fundamentami serwisu {brand}.",
    "admin.surveys.confirm_delete_question": "Usunąć to pytanie?",
    "admin.surveys.questions_count": "{count} pytań",
    "admin.surveys.questions_in_sheet": "{count} pytań w arkuszu",
    "admin.user_delete.anonymize_confirm": "Czy na pewno chcesz zanonimizować użytkownika #{id}? Tej operacji nie można cofnąć.",
    "admin.user_delete.anonymize_title": "Potwierdź anonimizację",
    "admin.user_delete.bad": "BŁĄD",
    "admin.user_delete.confirm_phrase": "USUŃ UŻYTKOWNIKA",
    "admin.user_delete.hard_delete_confirm": "Uwaga: całkowite usunięcie użytkownika #{id} i wszystkich powiązanych danych. Tej operacji nie można cofnąć.",
    "admin.user_delete.hard_delete_title": "POTWIERDŹ TRWAŁE USUNIĘCIE",
    "admin.user_delete.type_phrase": "Wpisz: {phrase}",
    "admin.users.payout_requires_wallet": "Wypłaty wymagają aktywnego portfela. Zgoda na wypłaty również została cofnięta.",
    "controller.admin.author_block_removed": "Blokada została zdjęta.",
    "controller.admin.author_blocked": "Autor został zablokowany.",
    "controller.admin.manual_talent_award": "Ręczne naliczenie Talentów (+{points} TT). Powód: {reason}",
    "controller.admin.payout_status_change": "Zmiana statusu wypłaty #{id} na {status}. Notatka: {note}",
    "controller.admin.translation_incomplete": "Wersja {language} jest niekompletna. Wpisz tytuł i treść albo pozostaw całą wersję pustą.",
    "controller.aiadmin.ai_task_planned": "Zaplanowano zadanie AI #{id}. To jest tylko plan — bez tłumaczenia i bez wywołania OpenAI.",
    "controller.finance.transfer_approved": "Transfer #{id} został zatwierdzony i zaksięgowany.",
    "controller.finance.transfer_rejected": "Transfer #{id} został odrzucony.",
    "controller.stripewebhook.processing_failed": "Błąd przetwarzania; referencja {reference}",
    "article.access.free": "bezpłatny",
    "article.access.paid": "płatny",
    "article.label.aria": "Etykieta artykułu: {label}",
    "article.status.approved": "zatwierdzony",
    "article.status.archived": "zarchiwizowany",
    "article.status.draft": "szkic",
    "article.status.published": "opublikowany",
    "article.status.rejected": "odrzucony",
    "article.status.review": "w redakcji",
    "article.status.submitted": "wysłany",
    "author.article.confirm_remove": "Usunąć zdjęcie?",
    "author.article.remove_error": "Nie udało się usunąć zdjęcia.",
    "author.article.removed": "Zdjęcie zostało usunięte.",
    "author.article.removing": "Usuwanie...",
    "author.article.saved_changes": "Zmiany zostały zapisane.",
    "author.article.saving": "Zapisywanie...",
    "author.dashboard.ajax.approval_required": "Publikacja wymaga zatwierdzenia redakcji.",
    "author.dashboard.ajax.save_error": "Nie udało się zapisać zmian.",
    "author.dashboard.ajax.send": "Wyślij do redakcji",
    "author.dashboard.ajax.sending": "Wysyłanie...",
    "author.dashboard.ajax.sent": "Tekst został wysłany do redakcji.",
    "author.dashboard.items_count": "{count} pozycji",
    "author.article.proofread_at": "tekst po korekcie redakcyjnej: {date}",
    "campaign.type.banner": "Baner",
    "campaign.type.campaign": "Kampania",
    "campaign.type.video": "Film",
    "common.active": "Aktywna",
    "common.connection_error": "Błąd połączenia. Spróbuj ponownie.",
    "common.inactive": "Wyłączona",
    "common.save_error": "Nie udało się zapisać zmian.",
    "common.saved": "Zapisano",
    "common.saving": "Zapisywanie...",
    "error.generic": "Wystąpił błąd. Spróbuj ponownie.",
    "error.title": "Dostęp ograniczony",
    "profile.currency.auto": "Automatycznie (zalecane)",
    "ui.partials.translation_status_legend.brak_tumaczenia": "Brak tłumaczenia",
    "ui.partials.translation_status_legend.orygina": "Oryginał",
    "upload.image.invalid_type": "Plik musi być obrazem JPG, PNG albo WEBP.",
}
EXPLICIT_TRANSLATIONS = {
    "admin.editorial_edit.last_proofreading": {
        "pl": "ostatnia korekta: {date}",
        "en": "Last proofreading: {date}",
        "de": "Letzte Korrektur: {date}",
        "fr": "Dernière correction : {date}",
        "it": "Ultima revisione: {date}",
        "es": "Última corrección: {date}",
    },
}
ATTRIBUTE_PATTERN = re.compile(
    r"\b(placeholder|title|aria-label|alt)\s*=\s*([\"'])([^\"']*)\2",
    re.IGNORECASE,
)
TEXT_PATTERN = re.compile(r">([^<>]*)<")
BLOCK_PATTERN = re.compile(r"<(script|style)\b[\s\S]*?</\1\s*>", re.IGNORECASE)
PHP_PATTERN = re.compile(r"<\?(?:php|=)?[\s\S]*?\?>")
PHP_STRING_PATTERN = re.compile(r"(?P<quote>['\"])(?P<value>(?:\\.|(?!\1)[\s\S])*?)(?P=quote)")
NEUTRAL_TEXT = {
    "AI", "API", "CSV", "EUR", "GBP", "Google", "Apple", "ID", "JSON",
    "PLN", "TT", "UTC", "3DORS", "Ź", "s", "px", "webhook", "webhooki",
}


def load_json(path: pathlib.Path) -> dict[str, dict[str, str]]:
    if not path.exists():
        return {}
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError(f"Catalog is not an object: {path}")
    return value


def local_environment() -> dict[str, str]:
    values = dict(os.environ)
    path = ROOT / ".env"
    if not path.exists():
        return values
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        values.setdefault(key.strip(), value)
    return values


def human_text(value: str) -> bool:
    text = re.sub(r"\s+", " ", value).strip()
    if not text or text in NEUTRAL_TEXT:
        return False
    if "<?" in text or "?>" in text:
        return False
    if not re.search(r"[A-Za-zÀ-ž]", text):
        return False
    if re.fullmatch(r"[A-Za-z0-9_.:/@#?&=%+\-]+", text) and re.search(r"[./@#?&=%]", text):
        return False
    return True


def slug(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii")
    normalized = re.sub(r"[^a-zA-Z0-9]+", "_", normalized.lower()).strip("_")
    if not normalized:
        normalized = "text"
    if len(normalized) > 64:
        normalized = normalized[:55].rstrip("_") + "_" + hashlib.sha1(value.encode()).hexdigest()[:8]
    return normalized


def key_prefix(path: pathlib.Path) -> str:
    relative = path.relative_to(VIEWS).with_suffix("")
    parts = [re.sub(r"[^a-z0-9]+", "_", part.lower()).strip("_") for part in relative.parts]
    if parts[0] == "admin":
        return "admin." + ".".join(parts[1:])
    return "ui." + ".".join(parts)


def protected_ranges(source: str) -> list[tuple[int, int]]:
    ranges = [(match.start(), match.end()) for match in BLOCK_PATTERN.finditer(source)]
    ranges.extend((match.start(), match.end()) for match in PHP_PATTERN.finditer(source))
    return ranges


def in_ranges(start: int, end: int, ranges: list[tuple[int, int]]) -> bool:
    return any(start < range_end and end > range_start for range_start, range_end in ranges)


def exact_polish_index(*catalogs: dict[str, dict[str, str]]) -> dict[str, str]:
    result: dict[str, str] = {}
    for catalog in catalogs:
        for key, entry in catalog.items():
            if isinstance(entry, dict) and isinstance(entry.get("pl"), str):
                result.setdefault(re.sub(r"\s+", " ", entry["pl"]).strip(), key)
    return result


def assign_key(
    path: pathlib.Path,
    text: str,
    catalog: dict[str, dict[str, str]],
    known_polish: dict[str, str],
    used_keys: set[str],
) -> str:
    normalized = re.sub(r"\s+", " ", text).strip()
    existing = known_polish.get(normalized)
    if existing:
        used_keys.add(existing)
        return existing
    base = key_prefix(path) + "." + slug(normalized)
    key = base
    counter = 2
    while key in catalog and catalog[key].get("pl") != normalized:
        key = f"{base}_{counter}"
        counter += 1
    catalog.setdefault(key, {"pl": normalized})
    known_polish.setdefault(normalized, key)
    used_keys.add(key)
    return key


def localize_view(
    path: pathlib.Path,
    source: str,
    catalog: dict[str, dict[str, str]],
    known_polish: dict[str, str],
    used_keys: set[str],
) -> tuple[str, int]:
    ranges = protected_ranges(source)
    replacements: list[tuple[int, int, str]] = []

    for match in TEXT_PATTERN.finditer(source):
        if in_ranges(match.start(), match.end(), ranges):
            continue
        raw = match.group(1)
        if PHP_PATTERN.search(raw) or not human_text(raw):
            continue
        leading = raw[: len(raw) - len(raw.lstrip())]
        trailing = raw[len(raw.rstrip()) :]
        text = re.sub(r"\s+", " ", raw).strip()
        key = assign_key(path, text, catalog, known_polish, used_keys)
        replacements.append((match.start(1), match.end(1), f"{leading}<?= e(t('{key}')) ?>{trailing}"))

    for match in ATTRIBUTE_PATTERN.finditer(source):
        if in_ranges(match.start(), match.end(), ranges):
            continue
        value = match.group(3)
        if not human_text(value):
            continue
        key = assign_key(path, value, catalog, known_polish, used_keys)
        replacement = f'{match.group(1)}="<?= e(t(\'{key}\')) ?>"'
        replacements.append((match.start(), match.end(), replacement))

    for start, end, replacement in sorted(replacements, reverse=True):
        source = source[:start] + replacement + source[end:]
    return source, len(replacements)


def php_comment_ranges(block: str) -> list[tuple[int, int]]:
    ranges = [(match.start(), match.end()) for match in re.finditer(r"/\*[\s\S]*?\*/", block)]
    ranges.extend((match.start(), match.end()) for match in re.finditer(r"(?m)//[^\r\n]*|#[^\r\n]*", block))
    return ranges


def localize_php_literals(
    path: pathlib.Path,
    source: str,
    catalog: dict[str, dict[str, str]],
    known_polish: dict[str, str],
    used_keys: set[str],
) -> tuple[str, int]:
    replacements: list[tuple[int, int, str]] = []
    for php_match in PHP_PATTERN.finditer(source):
        block = php_match.group(0)
        comments = php_comment_ranges(block)
        for string_match in PHP_STRING_PATTERN.finditer(block):
            if in_ranges(string_match.start(), string_match.end(), comments):
                continue
            value = string_match.group("value")
            if "$" in value or not re.search(r"[ĄĆĘŁŃÓŚŹŻąćęłńóśźż]", value):
                continue
            decoded = value.replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\")
            line_start = block.rfind("\n", 0, string_match.start()) + 1
            line_end = block.find("\n", string_match.end())
            line = block[line_start : line_end if line_end >= 0 else len(block)]
            if re.search(r"(?:=|<|>|\b(?:data|class|title)\s*=)", decoded, re.IGNORECASE):
                continue
            if "str_contains(" in line or "settingsByGroup" in line:
                continue
            key = assign_key(path, decoded, catalog, known_polish, used_keys)
            replacements.append(
                (php_match.start() + string_match.start(), php_match.start() + string_match.end(), f"t('{key}')")
            )
    for start, end, replacement in sorted(replacements, reverse=True):
        source = source[:start] + replacement + source[end:]
    return source, len(replacements)


def translate_batch(
    entries: dict[str, dict[str, str]],
    api_key: str,
    model: str,
) -> dict[str, dict[str, str]]:
    requested = {key: entry["pl"] for key, entry in entries.items()}
    system = (
        "Translate Polish interface copy for a professional news and publishing service. "
        "Return only a JSON object. Preserve placeholders in braces, HTML entities, TT, PLN, "
        "3DORS, Talent, Safety Fund and product names exactly. Use natural concise UI language. "
        "For every input key return exactly an object with en, de, fr, it and es strings."
    )
    body = json.dumps(
        {
            "model": model,
            "temperature": 0,
            "response_format": {"type": "json_object"},
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": json.dumps(requested, ensure_ascii=False)},
            ],
        },
        ensure_ascii=False,
    ).encode("utf-8")
    request = urllib.request.Request(
        "https://api.openai.com/v1/chat/completions",
        data=body,
        method="POST",
        headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
    )
    last_error: Exception | None = None
    for attempt in range(4):
        try:
            with urllib.request.urlopen(request, timeout=120) as response:
                payload = json.loads(response.read().decode("utf-8"))
            content = payload["choices"][0]["message"]["content"]
            translated = json.loads(content)
            if not isinstance(translated, dict):
                raise RuntimeError("Translation response is not a JSON object")
            result: dict[str, dict[str, str]] = {}
            for key, polish in requested.items():
                values = translated.get(key)
                if not isinstance(values, dict):
                    raise RuntimeError(f"Missing translation object for {key}")
                result[key] = {"pl": polish}
                for language in LANGUAGES[1:]:
                    value = values.get(language)
                    if not isinstance(value, str) or not value.strip():
                        raise RuntimeError(f"Missing {language} translation for {key}")
                    result[key][language] = value.strip()
            return result
        except (OSError, KeyError, ValueError, RuntimeError, urllib.error.HTTPError) as error:
            last_error = error
            if attempt < 3:
                time.sleep(2**attempt)
    raise RuntimeError(f"Translation request failed: {last_error}")


def google_translation(text: str, target_language: str) -> str:
    body = urllib.parse.urlencode(
        {"client": "gtx", "sl": "pl", "tl": target_language, "dt": "t", "q": text}
    ).encode("utf-8")
    request = urllib.request.Request(
        "https://translate.googleapis.com/translate_a/single",
        data=body,
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded", "User-Agent": "Mozilla/5.0"},
    )
    last_error: Exception | None = None
    for attempt in range(5):
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                payload = json.loads(response.read().decode("utf-8"))
            translated = "".join(str(part[0]) for part in payload[0] if part and part[0] is not None)
            time.sleep(0.8)
            return translated
        except (OSError, ValueError, TypeError, urllib.error.HTTPError) as error:
            last_error = error
            if attempt < 4:
                delay = 30 * (attempt + 1) if isinstance(error, urllib.error.HTTPError) and error.code == 429 else 2**attempt
                time.sleep(delay)
    raise RuntimeError(f"Google translation request failed: {last_error}")


def translate_google_batch(entries: dict[str, dict[str, str]]) -> dict[str, dict[str, str]]:
    keys = list(entries)
    protected: list[str] = []
    placeholders: dict[str, list[str]] = {}
    for index, key in enumerate(keys):
        source = entries[key]["pl"]
        placeholders[key] = re.findall(r"\{[A-Za-z0-9_]+\}", source)
        for placeholder_index, placeholder in enumerate(placeholders[key]):
            source = source.replace(placeholder, f"ZXPLACEHOLDER{placeholder_index}XZ")
        protected.append(source)
    markers = [f"<<<UISEP_{index:04d}>>>" for index in range(1, len(keys))]
    joined = protected[0] if protected else ""
    for marker, source in zip(markers, protected[1:]):
        joined += "\n" + marker + "\n" + source

    result = {key: {"pl": entries[key]["pl"]} for key in keys}
    marker_pattern = r"\s*<<<UISEP_\d{4}>>>\s*"
    for language in LANGUAGES[1:]:
        translated = google_translation(joined, language)
        parts = re.split(marker_pattern, translated)
        if len(parts) != len(keys):
            raise RuntimeError(
                f"Translation separator mismatch for {language}: expected {len(keys)}, got {len(parts)}"
            )
        for key, value in zip(keys, parts):
            value = value.strip()
            for placeholder_index, placeholder in enumerate(placeholders[key]):
                value = re.sub(
                    rf"ZX\s*PLACEHOLDER\s*{placeholder_index}\s*XZ",
                    placeholder,
                    value,
                    flags=re.IGNORECASE,
                )
            if not value:
                raise RuntimeError(f"Empty {language} translation for {key}")
            result[key][language] = value
    return result


def protect_terms(text: str) -> tuple[str, dict[str, str]]:
    terms = re.findall(r"\{[A-Za-z0-9_]+\}", text)
    for fixed in ("Safety Fund", "Program Talent", "Talent", "3DORS", "PLN", "TT"):
        if fixed in text and fixed not in terms:
            terms.append(fixed)
    replacements: dict[str, str] = {}
    for index, term in enumerate(sorted(terms, key=len, reverse=True)):
        marker = f"ZXTERM{index}XZ"
        text = text.replace(term, marker)
        replacements[marker] = term
    return text, replacements


def restore_terms(text: str, replacements: dict[str, str]) -> str:
    for marker, term in replacements.items():
        marker_index = re.search(r"\d+", marker)
        pattern = rf"ZX\s*TERM\s*{marker_index.group(0) if marker_index else '0'}\s*X(?:Z)?"
        text = re.sub(pattern, term, text, flags=re.IGNORECASE)
    return text.strip()


def invalidate_broken_placeholders(catalog: dict[str, dict[str, str]]) -> None:
    for entry in catalog.values():
        if not isinstance(entry, dict) or not isinstance(entry.get("pl"), str):
            continue
        expected = set(re.findall(r"\{[A-Za-z0-9_]+\}", entry["pl"]))
        for language in LANGUAGES[1:]:
            value = str(entry.get(language, ""))
            actual = set(re.findall(r"\{[A-Za-z0-9_]+\}", value))
            if actual != expected or "ZXTERM" in value.upper():
                entry[language] = ""


def fill_argos_translations(
    catalog: dict[str, dict[str, str]],
    checkpoint_path: pathlib.Path | None,
) -> None:
    from argostranslate import translate as argos_translate

    pending = [
        key
        for key, entry in catalog.items()
        if isinstance(entry, dict) and any(not str(entry.get(language, "")).strip() for language in LANGUAGES)
    ]
    for index, key in enumerate(pending, start=1):
        entry = catalog[key]
        polish = entry["pl"]
        if str(entry.get("en", "")).strip():
            english = str(entry["en"]).strip()
        else:
            protected_polish, polish_replacements = protect_terms(polish)
            english = restore_terms(
                argos_translate.translate(protected_polish, "pl", "en"),
                polish_replacements,
            )
            entry["en"] = english
        english_protected, english_replacements = protect_terms(english)
        for language in LANGUAGES[2:]:
            if str(entry.get(language, "")).strip():
                continue
            target = argos_translate.translate(english_protected, "en", language)
            entry[language] = restore_terms(target, english_replacements)
        if checkpoint_path is not None and (index % 20 == 0 or index == len(pending)):
            save_catalog(checkpoint_path, catalog)
            print(f"translated {index}/{len(pending)}", flush=True)


def fill_translations(
    catalog: dict[str, dict[str, str]],
    provider: str,
    api_key: str = "",
    model: str = "gpt-4.1-mini",
    checkpoint_path: pathlib.Path | None = None,
) -> None:
    if provider == "argos":
        fill_argos_translations(catalog, checkpoint_path)
        return
    pending = {
        key: entry
        for key, entry in catalog.items()
        if isinstance(entry, dict) and any(not str(entry.get(language, "")).strip() for language in LANGUAGES)
    }
    keys = list(pending)
    batch_size = 35 if provider == "openai" else 50
    for start in range(0, len(keys), batch_size):
        batch_keys = keys[start : start + batch_size]
        batch = {key: pending[key] for key in batch_keys}
        translated = translate_batch(batch, api_key, model) if provider == "openai" else translate_google_batch(batch)
        for key, values in translated.items():
            catalog[key] = values
        if checkpoint_path is not None:
            save_catalog(checkpoint_path, catalog)
        print(f"translated {min(start + len(batch_keys), len(keys))}/{len(keys)}", flush=True)


def save_catalog(path: pathlib.Path, catalog: dict[str, dict[str, str]]) -> None:
    path.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--translate", action="store_true")
    parser.add_argument("--provider", choices=("argos", "google", "openai"), default="argos")
    parser.add_argument(
        "--recover-git-head",
        action="store_true",
        help="Rebuild deterministic UI keys from the pre-localization views stored in Git HEAD.",
    )
    args = parser.parse_args()

    public_catalog = load_json(PUBLIC_CATALOG)
    admin_catalog = load_json(ADMIN_CATALOG)
    safety_catalog = load_json(SAFETY_CATALOG)
    invalidate_broken_placeholders(public_catalog)
    invalidate_broken_placeholders(admin_catalog)
    invalidate_broken_placeholders(safety_catalog)
    admin_prefixes = (
        "admin.",
        "controller.admin",
        "controller.aiadmin",
        "controller.finance",
        "controller.paymentwebhook",
        "controller.stripewebhook",
    )
    for key, polish in EXPLICIT_POLISH.items():
        if key.startswith(admin_prefixes):
            moved = public_catalog.pop(key, None)
            admin_catalog.setdefault(key, moved if isinstance(moved, dict) else {"pl": polish})
        else:
            public_catalog.setdefault(key, {"pl": polish})
    for key, translations in EXPLICIT_TRANSLATIONS.items():
        target = admin_catalog if key.startswith("admin.") else public_catalog
        target[key] = dict(translations)
    known_polish = exact_polish_index(public_catalog, admin_catalog, safety_catalog)
    changed: dict[pathlib.Path, str] = {}
    used_keys: set[str] = set()
    total = 0

    if args.recover_git_head:
        for path in sorted(VIEWS.rglob("*.php")):
            relative = path.relative_to(ROOT).as_posix()
            result = subprocess.run(
                ["git", "show", f"HEAD:{relative}"],
                cwd=ROOT,
                capture_output=True,
                check=False,
            )
            if result.returncode != 0:
                continue
            source = result.stdout.decode("utf-8")
            target_catalog = admin_catalog if path.relative_to(VIEWS).parts[0] == "admin" else public_catalog
            recovered, _ = localize_view(path, source, target_catalog, known_polish, used_keys)
            localize_php_literals(path, recovered, target_catalog, known_polish, used_keys)

    for path in sorted(VIEWS.rglob("*.php")):
        source = path.read_text(encoding="utf-8")
        used_keys.update(re.findall(r"\bt\(\s*['\"]([^'\"]+)['\"]", source))
        target_catalog = admin_catalog if path.relative_to(VIEWS).parts[0] == "admin" else public_catalog
        localized, count = localize_view(path, source, target_catalog, known_polish, used_keys)
        localized, php_count = localize_php_literals(
            path, localized, target_catalog, known_polish, used_keys
        )
        count += php_count
        if count:
            changed[path] = localized
            total += count

    public_catalog = {
        key: entry for key, entry in public_catalog.items() if not key.startswith("ui.") or key in used_keys
    }
    admin_catalog = {
        key: entry for key, entry in admin_catalog.items() if not key.startswith("admin.") or key in used_keys
    }
    new_public = sum(1 for entry in public_catalog.values() if any(not str(entry.get(lang, "")).strip() for lang in LANGUAGES))
    new_admin = sum(1 for entry in admin_catalog.values() if any(not str(entry.get(lang, "")).strip() for lang in LANGUAGES))
    print(f"view replacements={total} files={len(changed)} pending_public={new_public} pending_admin={new_admin}")
    if not args.apply:
        return 0

    if args.translate:
        environment = local_environment()
        api_key = environment.get("OPENAI_API_KEY", "").strip()
        if args.provider == "openai" and not api_key:
            raise RuntimeError("OPENAI_API_KEY is required for --translate")
        model = environment.get("OPENAI_TRANSLATION_MODEL", environment.get("OPENAI_MODEL", "gpt-4.1-mini"))
        fill_translations(public_catalog, args.provider, api_key, model, PUBLIC_CATALOG)
        fill_translations(admin_catalog, args.provider, api_key, model, ADMIN_CATALOG)

    for catalog_name, catalog in (("public", public_catalog), ("admin", admin_catalog)):
        missing = [key for key, entry in catalog.items() if any(not str(entry.get(lang, "")).strip() for lang in LANGUAGES)]
        if missing:
            raise RuntimeError(f"{catalog_name} catalog still has {len(missing)} incomplete entries")

    for path, source in changed.items():
        path.write_text(source, encoding="utf-8")
    save_catalog(PUBLIC_CATALOG, public_catalog)
    save_catalog(ADMIN_CATALOG, admin_catalog)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
