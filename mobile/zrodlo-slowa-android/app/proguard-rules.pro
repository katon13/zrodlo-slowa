# Naprawa P2-6 z audytu ("Brak pełnego hardeningu release" — isMinifyEnabled=false):
# release ma być zaciemniony i zoptymalizowany przez R8 (patrz app/build.gradle.kts).
# Reguły domyślne (getDefaultProguardFile) wystarczają dla obecnego zestawu
# zależności (AndroidX/Compose/WebView) — projekt nie używa refleksji ani
# serializacji wymagającej dodatkowych `-keep`.
