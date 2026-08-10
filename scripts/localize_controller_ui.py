#!/usr/bin/env python3
"""Move human-facing Polish controller messages into the JSON UI catalogs.

The pass is intentionally conservative: it skips comments, log-only messages,
comparisons and mobile API contracts. It covers flash messages, validation
errors, page titles, status labels and other copy returned by WWW controllers.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import re

from localize_static_views import (
    ADMIN_CATALOG,
    LANGUAGES,
    PUBLIC_CATALOG,
    ROOT,
    SAFETY_CATALOG,
    exact_polish_index,
    fill_translations,
    load_json,
    local_environment,
    php_comment_ranges,
    save_catalog,
    slug,
)

CONTROLLERS = ROOT / "app" / "Controllers"
POLISH = re.compile(r"[ĄĆĘŁŃÓŚŹŻąćęłńóśźż]")
PHP_STRING = re.compile(r"(?P<quote>['\"])(?P<value>(?:\\.|(?!\1)[\s\S])*?)(?P=quote)")
SKIP_FILES = {"Dors3MobileApiController.php", "MobileSessionController.php"}
SKIP_LINE = re.compile(
    r"error_log\s*\(|JsonErrorLogger|str_(?:contains|starts_with|ends_with)\s*\(|preg_(?:match|replace)\s*\(|===|!=="
)
UI_SINK_LINE = re.compile(
    r"session->flash|safeError\s*\(|jsonError\s*\(|throw new|['\"](?:title|message|label)['\"]\s*=>|\$msg\s*=|\$reason\s*=|return\s+\$this->view"
)


def inside(position: int, ranges: list[tuple[int, int]]) -> bool:
    return any(start <= position < end for start, end in ranges)


def target_catalog(path: pathlib.Path, public: dict, admin: dict) -> dict:
    return admin if path.name in {
        "AdminController.php",
        "AdminArticleTranslationController.php",
        "AiAdminController.php",
        "FinanceController.php",
        "PaymentWebhookController.php",
    } else public


def localize(path: pathlib.Path, source: str, catalog: dict, known: dict[str, str]) -> tuple[str, int]:
    comments = php_comment_ranges(source)
    replacements: list[tuple[int, int, str]] = []
    prefix = "controller." + re.sub(r"controller$", "", path.stem.lower()).strip("_")
    for match in PHP_STRING.finditer(source):
        if inside(match.start(), comments):
            continue
        if re.match(r"\s*=>", source[match.end() :]):
            continue
        value = match.group("value")
        if "$" in value:
            continue
        line_start = source.rfind("\n", 0, match.start()) + 1
        line_end = source.find("\n", match.end())
        line = source[line_start : line_end if line_end >= 0 else len(source)]
        if SKIP_LINE.search(line):
            continue
        decoded = value.replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\")
        ascii_ui = (
            UI_SINK_LINE.search(line) is not None
            and re.search(r"[A-Za-z]", decoded) is not None
            and (" " in decoded or decoded[:1].isupper())
            and not (
                re.fullmatch(r"[A-Za-z0-9_.:/@#?&=%+\-]+", decoded)
                and re.search(r"[_.:/@#?&=%+]", decoded)
            )
        )
        if not POLISH.search(decoded) and not ascii_ui:
            continue
        key = known.get(re.sub(r"\s+", " ", decoded).strip())
        if key is None:
            base = f"{prefix}.{slug(decoded)}"
            key = base
            counter = 2
            while key in catalog and catalog[key].get("pl") != decoded:
                key = f"{base}_{counter}"
                counter += 1
            catalog.setdefault(key, {"pl": decoded})
            known.setdefault(re.sub(r"\s+", " ", decoded).strip(), key)
        replacements.append((match.start(), match.end(), f"t('{key}')"))
    for start, end, replacement in sorted(replacements, reverse=True):
        source = source[:start] + replacement + source[end:]
    return source, len(replacements)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--translate", action="store_true")
    parser.add_argument("--provider", choices=("argos", "google", "openai"), default="argos")
    args = parser.parse_args()

    public = load_json(PUBLIC_CATALOG)
    admin = load_json(ADMIN_CATALOG)
    safety = load_json(SAFETY_CATALOG)
    known = exact_polish_index(public, admin, safety)
    changed: dict[pathlib.Path, str] = {}
    total = 0
    for path in sorted(CONTROLLERS.glob("*.php")):
        if path.name in SKIP_FILES:
            continue
        source = path.read_text(encoding="utf-8")
        localized, count = localize(path, source, target_catalog(path, public, admin), known)
        if count:
            changed[path] = localized
            total += count

    print(f"controller replacements={total} files={len(changed)}")
    if not args.apply:
        return 0
    if args.translate:
        environment = local_environment()
        api_key = environment.get("OPENAI_API_KEY", "").strip()
        if args.provider == "openai" and not api_key:
            raise RuntimeError("OPENAI_API_KEY is required for --translate")
        model = environment.get("OPENAI_TRANSLATION_MODEL", environment.get("OPENAI_MODEL", "gpt-4.1-mini"))
        fill_translations(public, args.provider, api_key, model, PUBLIC_CATALOG)
        fill_translations(admin, args.provider, api_key, model, ADMIN_CATALOG)

    for name, catalog in (("public", public), ("admin", admin)):
        incomplete = [key for key, entry in catalog.items() if any(not str(entry.get(lang, "")).strip() for lang in LANGUAGES)]
        if incomplete:
            raise RuntimeError(f"{name} catalog still has {len(incomplete)} incomplete entries")
    for path, source in changed.items():
        path.write_text(source, encoding="utf-8")
    save_catalog(PUBLIC_CATALOG, public)
    save_catalog(ADMIN_CATALOG, admin)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
