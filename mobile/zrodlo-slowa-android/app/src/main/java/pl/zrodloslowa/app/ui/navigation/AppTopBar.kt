package pl.zrodloslowa.app.ui.navigation

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pl.zrodloslowa.app.R
import pl.zrodloslowa.app.config.SiteConfig

/**
 * Kompaktowy natywny nagłówek aplikacji (ETAP 1, pkt 3.1/3 dyspozycji):
 * oficjalne logo + wyszukiwarka + menu hamburger — w miejsce pełnego,
 * dublującego nawigację nagłówka strony WWW (który jest teraz ukrywany
 * wewnątrz WebView, patrz [pl.zrodloslowa.app.webview.SecureWebView]).
 * Hamburger zastępuje też stały przycisk "Dołącz" (pkt 3.2) oraz dawny,
 * osobny pasek językowy pod status barem (pkt 3.4/7) — wybór wersji
 * językowej jest tu jedną z pozycji menu.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppTopBar(
    brandName: String,
    wordmarkLine1: String,
    wordmarkLine2: String,
    selectedLanguageCode: String,
    onSearchClick: () -> Unit,
    onMenuPathSelected: (String) -> Unit,
    onLanguageSelected: (String) -> Unit,
    onAutomaticLanguageSelected: () -> Unit,
    onAccountClick: () -> Unit,
) {
    var menuExpanded by remember { mutableStateOf(false) }
    var languageSubmenuExpanded by remember { mutableStateOf(false) }

    TopAppBar(
        title = {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.clearAndSetSemantics { contentDescription = brandName },
            ) {
                Image(
                    painter = painterResource(id = R.drawable.ic_source_mark_red),
                    contentDescription = null,
                    modifier = Modifier.size(36.dp),
                )
                Spacer(modifier = Modifier.width(8.dp))
                Column {
                    BrandWordmarkLine(wordmarkLine1)
                    BrandWordmarkLine(wordmarkLine2)
                }
            }
        },
        actions = {
            IconButton(onClick = onSearchClick) {
                Icon(
                    imageVector = Icons.Filled.Search,
                    contentDescription = stringResource(R.string.action_search),
                )
            }
            IconButton(onClick = { menuExpanded = true }) {
                Icon(
                    imageVector = Icons.Filled.Menu,
                    contentDescription = stringResource(R.string.action_menu),
                )
            }
            DropdownMenu(expanded = menuExpanded, onDismissRequest = { menuExpanded = false }) {
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_latest)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("articles?cat=najnowsze") },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_topics)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("articles") },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_polls)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("surveys") },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_ads)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("campaigns") },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_how_to_earn)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("jak-zarabiac") },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_authors)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("authors") },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.action_change_language)) },
                    onClick = { menuExpanded = false; languageSubmenuExpanded = true },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_tt_rate)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("wallet") },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_login_account)) },
                    onClick = { menuExpanded = false; onAccountClick() },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.menu_join_author)) },
                    onClick = { menuExpanded = false; onMenuPathSelected("register") },
                )
            }
            DropdownMenu(
                expanded = languageSubmenuExpanded,
                onDismissRequest = { languageSubmenuExpanded = false },
            ) {
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.action_language_automatic)) },
                    onClick = {
                        languageSubmenuExpanded = false
                        onAutomaticLanguageSelected()
                    },
                )
                SiteConfig.sites.forEach { site ->
                    DropdownMenuItem(
                        text = { Text(text = site.brandName) },
                        onClick = {
                            languageSubmenuExpanded = false
                            if (site.languageCode != selectedLanguageCode) {
                                onLanguageSelected(site.languageCode)
                            }
                        },
                    )
                }
            }
        },
    )
}

@Composable
private fun BrandWordmarkLine(text: String) {
    Text(
        text = text,
        maxLines = 1,
        softWrap = false,
        overflow = TextOverflow.Clip,
        fontSize = 13.sp,
        lineHeight = 13.sp,
        fontWeight = FontWeight.Black,
        letterSpacing = 0.4.sp,
    )
}
