package pl.zrodloslowa.app.ui.intro

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.nativeCanvas

/** Kolory czołówki — jasne, kremowe tło + oficjalna czerwień znaku. */
val IntroBackgroundColor = Color(0xFFFBF6EE)
val IntroRedColor = Color(0xFFB90012)
val IntroTextColor = Color(0xFF1A1A1A)

/** Dokładna geometria białego znaku z `public/assets/img/logo/logo-mark.svg`. */
fun buildSourceMarkPath(size: Float): Path {
    val s = size / 180f
    return Path().apply {
        moveTo(69f * s, 48f * s)
        cubicTo(69f * s, 44f * s, 72f * s, 41f * s, 76f * s, 41f * s)
        lineTo(104f * s, 41f * s)
        cubicTo(108f * s, 41f * s, 111f * s, 44f * s, 111f * s, 48f * s)
        lineTo(111f * s, 120f * s)
        lineTo(90f * s, 134f * s)
        lineTo(69f * s, 120f * s)
        close()
    }
}

/**
 * Programowe odtworzenie początku filmu referencyjnego:
 * 1) segmentowe „źródełko” płynie z góry,
 * 2) składa się w oficjalny czerwony kwadrat z białym znakiem,
 * 3) kontrolowany dwuwierszowy wordmark wjeżdża z prawej i kończy jako
 *    jeden, geometrycznie wycentrowany lockup.
 *
 * Czas i przejście do aplikacji nadal kontroluje niezmieniony [IntroTiming].
 */
@androidx.compose.runtime.Composable
fun SourceLogoAnimation(
    progress: Float,
    motto: String?,
    modifier: Modifier = Modifier,
    wordmarkLines: List<String> = listOf("ŹRÓDŁO", "SŁOWA"),
) {
    Box(modifier = modifier.fillMaxSize()) {
        Canvas(modifier = Modifier.fillMaxSize()) {
            val w = size.width
            val h = size.height
            drawRect(color = IntroBackgroundColor, size = size)

            drawTopLine(progress = IntroTiming.phaseProgress(progress, IntroTiming.LINE), w = w, h = h)

            val logoSize = kotlin.math.min(w * 0.24f, h * 0.17f)
            val normalizedLines = wordmarkLines.take(2).ifEmpty { listOf("ŹRÓDŁO", "SŁOWA") }
            val layoutPaint = createWordmarkPaint(logoSize = logoSize, alpha = 1f)
            val maxTextWidth = normalizedLines.maxOf(layoutPaint::measureText)
            val gap = logoSize * 0.18f
            val groupWidth = logoSize + gap + maxTextWidth
            val groupLeft = (w - groupWidth) / 2f
            val logoCenter = Offset(groupLeft + logoSize / 2f, h * 0.48f)

            val streamProgress = IntroTiming.phaseProgress(progress, IntroTiming.STREAM)
            val formProgress = IntroTiming.phaseProgress(progress, IntroTiming.LOGO_FORM)
            if (streamProgress > 0f && formProgress < 1f) {
                drawSourceStream(
                    streamProgress = streamProgress,
                    formProgress = formProgress,
                    target = logoCenter,
                    logoSize = logoSize,
                )
            }
            if (formProgress > 0f) {
                drawFormingOfficialMark(
                    formProgress = formProgress,
                    center = logoCenter,
                    logoSize = logoSize,
                )
            }

            val wordmarkProgress = IntroTiming.phaseProgress(progress, IntroTiming.WORDMARK)
            if (wordmarkProgress > 0f) {
                drawOfficialWordmark(
                    wordmarkProgress = wordmarkProgress,
                    textX = groupLeft + logoSize + gap,
                    centerY = logoCenter.y,
                    logoSize = logoSize,
                    lines = normalizedLines,
                )
            }

            val mottoProgress = IntroTiming.phaseProgress(progress, IntroTiming.MOTTO)
            if (motto != null && mottoProgress > 0f) {
                drawMotto(
                    mottoProgress = mottoProgress,
                    centerX = w / 2f,
                    baselineY = logoCenter.y + logoSize * 0.85f,
                    logoSize = logoSize,
                    text = motto,
                )
            }

            val fadeProgress = IntroTiming.phaseProgress(progress, IntroTiming.FADE_OUT)
            if (fadeProgress > 0f) {
                drawRect(color = IntroBackgroundColor.copy(alpha = fadeProgress), size = size)
            }
        }
    }
}

/** Cienka czerwona linia u góry po prawej — jak w filmie referencyjnym. */
private fun androidx.compose.ui.graphics.drawscope.DrawScope.drawTopLine(progress: Float, w: Float, h: Float) {
    if (progress <= 0f) return
    val y = h * 0.14f
    val maxLength = w * 0.32f
    val endX = w * 0.92f
    drawLine(
        color = IntroRedColor.copy(alpha = 0.9f),
        start = Offset(endX - maxLength * progress, y),
        end = Offset(endX, y),
        strokeWidth = 2.5f,
        cap = StrokeCap.Round,
    )
}

/** Dwie prowadnice i kolumna małych konturowych segmentów płynących z góry. */
private fun androidx.compose.ui.graphics.drawscope.DrawScope.drawSourceStream(
    streamProgress: Float,
    formProgress: Float,
    target: Offset,
    logoSize: Float,
) {
    val eased = smooth(streamProgress)
    val alpha = (1f - formProgress).coerceIn(0f, 1f)
    if (alpha <= 0f) return

    val startY = -logoSize * 0.35f
    val headY = startY + (target.y - startY) * eased
    val railHalfGap = logoSize * 0.075f
    val railStartY = kotlin.math.min(0f, headY - logoSize * 1.45f)
    val railEndY = headY + logoSize * 0.45f
    val railColor = IntroRedColor.copy(alpha = alpha * 0.72f)
    drawLine(
        color = railColor,
        start = Offset(target.x - railHalfGap, railStartY),
        end = Offset(target.x - railHalfGap, railEndY),
        strokeWidth = logoSize * 0.009f,
    )
    drawLine(
        color = railColor,
        start = Offset(target.x + railHalfGap, railStartY),
        end = Offset(target.x + railHalfGap, railEndY),
        strokeWidth = logoSize * 0.009f,
    )

    val segmentWidth = logoSize * 0.075f
    val segmentHeight = logoSize * 0.13f
    val step = logoSize * 0.165f
    repeat(7) { index ->
        val segmentTop = headY - index * step
        if (segmentTop + segmentHeight >= railStartY) {
            drawRect(
                color = IntroRedColor.copy(alpha = alpha * (0.78f + index * 0.025f)),
                topLeft = Offset(target.x - segmentWidth / 2f, segmentTop),
                size = Size(segmentWidth, segmentHeight),
                style = Stroke(width = logoSize * 0.012f),
            )
        }
    }
}

/** Segmenty kondensują się w pionowy prostokąt, a następnie oficjalny kwadrat. */
private fun androidx.compose.ui.graphics.drawscope.DrawScope.drawFormingOfficialMark(
    formProgress: Float,
    center: Offset,
    logoSize: Float,
) {
    val eased = smooth(formProgress)
    val markWidth = logoSize * (0.58f + 0.42f * eased)
    val markHeight = logoSize * (1.45f - 0.45f * eased)
    val left = center.x - markWidth / 2f
    val top = center.y - markHeight / 2f
    drawRect(
        color = IntroRedColor.copy(alpha = formProgress.coerceIn(0f, 1f)),
        topLeft = Offset(left, top),
        size = Size(markWidth, markHeight),
    )

    val officialMarkSize = markWidth
    val markTop = center.y - officialMarkSize / 2f
    translate(left = left, top = markTop) {
        drawPath(
            path = buildSourceMarkPath(officialMarkSize),
            color = Color.White.copy(alpha = formProgress.coerceIn(0f, 1f)),
        )
    }
}

/** Wordmark wjeżdża z prawej i kończy w dwóch kontrolowanych wierszach. */
private fun androidx.compose.ui.graphics.drawscope.DrawScope.drawOfficialWordmark(
    wordmarkProgress: Float,
    textX: Float,
    centerY: Float,
    logoSize: Float,
    lines: List<String>,
) {
    val eased = smooth(wordmarkProgress)
    val paint = createWordmarkPaint(logoSize = logoSize, alpha = eased)
    val lineAdvance = logoSize * 0.30f
    val metrics = paint.fontMetrics
    val baselineCentering = -(metrics.ascent + metrics.descent) / 2f
    val translatedX = textX + (1f - eased) * logoSize * 0.62f

    drawContext.canvas.nativeCanvas.apply {
        lines.forEachIndexed { index, line ->
            val centeredIndex = index - (lines.size - 1) / 2f
            val baseline = centerY + centeredIndex * lineAdvance + baselineCentering
            drawText(line, translatedX, baseline, paint)
        }
    }
}

private fun createWordmarkPaint(logoSize: Float, alpha: Float): android.graphics.Paint =
    android.graphics.Paint().apply {
        isAntiAlias = true
        color = IntroTextColor.copy(alpha = alpha).toArgb()
        textSize = logoSize * 0.255f
        typeface = android.graphics.Typeface.create(
            android.graphics.Typeface.SANS_SERIF,
            android.graphics.Typeface.BOLD,
        )
        letterSpacing = 0.035f
    }

private fun androidx.compose.ui.graphics.drawscope.DrawScope.drawMotto(
    mottoProgress: Float,
    centerX: Float,
    baselineY: Float,
    logoSize: Float,
    text: String,
) {
    val paint = android.graphics.Paint().apply {
        isAntiAlias = true
        color = IntroTextColor.copy(alpha = mottoProgress * 0.75f).toArgb()
        textSize = logoSize * 0.14f
        textAlign = android.graphics.Paint.Align.CENTER
    }
    drawContext.canvas.nativeCanvas.drawText(text, centerX, baselineY, paint)
}

private fun smooth(value: Float): Float = value * value * (3f - 2f * value)

private fun Color.toArgb(): Int = android.graphics.Color.argb(
    (alpha * 255).toInt().coerceIn(0, 255),
    (red * 255).toInt().coerceIn(0, 255),
    (green * 255).toInt().coerceIn(0, 255),
    (blue * 255).toInt().coerceIn(0, 255),
)
