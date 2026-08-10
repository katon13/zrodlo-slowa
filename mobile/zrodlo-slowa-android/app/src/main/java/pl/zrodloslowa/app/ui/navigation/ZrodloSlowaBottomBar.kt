package pl.zrodloslowa.app.ui.navigation

import androidx.compose.foundation.background
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.wrapContentHeight
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pl.zrodloslowa.app.navigation.AppDestination
import pl.zrodloslowa.app.notifications.notificationBadgeText

/**
 * Stały dolny pasek nawigacji zgodny z zaakceptowaną makietą wizualną (ETAP 4
 * dyspozycji): prostokątny, czarny pasek na pełną szerokość, oddzielony od
 * treści cienką czerwoną linią, która w środkowej części łagodnie unosi się
 * wokół okrągłego, wystającego przycisku Portfela. Pozostałe cztery zakładki
 * to płaskie ikony z podpisem; aktywna sekcja jest zaznaczona czerwienią.
 * Bez kłódki i bez symboliki 3DORS.
 */
@Composable
fun ZrodloSlowaBottomBar(
    currentDestination: AppDestination,
    unreadNotificationCount: Int = 0,
    onDestinationSelected: (AppDestination) -> Unit,
) {
    // Naprawa obserwacji z realnego testu na emulatorze
    // (docs/RAPORT_ODBIORU_NA_EMULATORZE.md, pkt 2 "Obserwacja wizualna"):
    // etykieta "Wallet" była zawsze czerwona jako stały akcent marki, co w
    // praktyce myliło użytkownika co do tego, która zakładka jest faktycznie
    // aktywna (widoczne np. przy wybranej zakładce Articles — obie etykiety
    // były jednocześnie czerwone). Kolor przycisku (okrągły FAB) pozostaje
    // stałym akcentem marki, ale etykieta tekstowa pod nim odzwierciedla
    // teraz rzeczywisty stan zaznaczenia, tak jak pozostałe cztery zakładki.
    val isWalletSelected = currentDestination == AppDestination.WALLET
    val barHeight = 64.dp
    val fabSize = 56.dp
    val riseHeight = 14.dp
    val barColor = MaterialTheme.colorScheme.background
    val lineColor = MaterialTheme.colorScheme.primary

    Box(
        modifier = Modifier
            .fillMaxWidth()
            .wrapContentHeight(align = Alignment.Bottom, unbounded = true),
    ) {
        Column(modifier = Modifier.fillMaxWidth()) {
            Canvas(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(riseHeight),
            ) {
                val width = size.width
                val centerX = width / 2f
                val bumpHalfWidth = width * 0.14f
                val path = Path().apply {
                    moveTo(0f, size.height)
                    lineTo(centerX - bumpHalfWidth, size.height)
                    cubicTo(
                        centerX - bumpHalfWidth * 0.45f, size.height,
                        centerX - bumpHalfWidth * 0.55f, 0f,
                        centerX, 0f,
                    )
                    cubicTo(
                        centerX + bumpHalfWidth * 0.55f, 0f,
                        centerX + bumpHalfWidth * 0.45f, size.height,
                        centerX + bumpHalfWidth, size.height,
                    )
                    lineTo(width, size.height)
                }
                drawPath(path = path, color = lineColor, style = androidx.compose.ui.graphics.drawscope.Stroke(width = 4f))
            }
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(barHeight)
                    .background(barColor)
                    .padding(horizontal = 4.dp),
                horizontalArrangement = androidx.compose.foundation.layout.Arrangement.SpaceEvenly,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                AppDestination.bottomBarItems.forEach { destination ->
                    if (destination == AppDestination.WALLET) {
                        Box(modifier = Modifier.weight(1f))
                    } else {
                        BottomBarFlatItem(
                            destination = destination,
                            selected = destination == currentDestination,
                            unreadCount = if (destination == AppDestination.NOTIFICATIONS) unreadNotificationCount else 0,
                            onClick = { onDestinationSelected(destination) },
                        )
                    }
                }
            }
        }

        val wallet = AppDestination.WALLET
        Column(
            modifier = Modifier
                .align(Alignment.TopCenter)
                .offset(y = -(fabSize / 2) + riseHeight),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(
                modifier = Modifier
                    .size(fabSize)
                    .shadow(elevation = 6.dp, shape = CircleShape)
                    .clip(CircleShape)
                    .background(MaterialTheme.colorScheme.primary)
                    .clickable(
                        interactionSource = remember { MutableInteractionSource() },
                        indication = null,
                        onClick = { onDestinationSelected(wallet) },
                    ),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = wallet.icon,
                    contentDescription = stringResource(wallet.labelRes),
                    tint = Color.White,
                )
            }
            Text(
                text = stringResource(wallet.labelRes),
                color = if (isWalletSelected) {
                    MaterialTheme.colorScheme.primary
                } else {
                    MaterialTheme.colorScheme.onSurfaceVariant
                },
                fontSize = 11.sp,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun RowScope.BottomBarFlatItem(
    destination: AppDestination,
    selected: Boolean,
    unreadCount: Int,
    onClick: () -> Unit,
) {
    val tint = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant
    Column(
        modifier = Modifier
            .weight(1f)
            .clickable(
                interactionSource = remember { MutableInteractionSource() },
                indication = null,
                onClick = onClick,
            )
            .padding(vertical = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(modifier = Modifier.size(30.dp), contentAlignment = Alignment.Center) {
            Icon(imageVector = destination.icon, contentDescription = null, tint = tint)
            val badgeText = notificationBadgeText(unreadCount)
            if (badgeText.isNotEmpty()) {
                Box(
                    modifier = Modifier
                        .align(Alignment.TopEnd)
                        .offset(x = 7.dp, y = (-5).dp)
                        .defaultMinSize(minWidth = 17.dp, minHeight = 17.dp)
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.primary)
                        .padding(horizontal = 4.dp, vertical = 1.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = badgeText,
                        color = Color.White,
                        fontSize = 9.sp,
                        lineHeight = 10.sp,
                    )
                }
            }
        }
        Text(
            text = stringResource(destination.labelRes),
            color = tint,
            fontSize = if (destination == AppDestination.NOTIFICATIONS) 9.sp else 11.sp,
            textAlign = TextAlign.Center,
            maxLines = 1,
        )
    }
}
