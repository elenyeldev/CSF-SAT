// Generador de PDF CSF SAT
// V 1.2 - Restaurado lógica de wrapping y layouts complejos

const PDF_CFG = {
    colors: {
        textGray: { type: 'RGB', r: 0.506, g: 0.506, b: 0.506 },
        lightGray: { type: 'RGB', r: 0.945, g: 0.945, b: 0.945 },
        white: { type: 'RGB', r: 1, g: 1, b: 1 },
        black: { type: 'RGB', r: 0, g: 0, b: 0 },
        red: { type: 'RGB', r: 1, g: 0, b: 0 },
        blue: { type: 'RGB', r: 0, g: 0, b: 1 },
        green: { type: 'RGB', r: 0, g: 1, b: 0 }
    },
    text: {
        baseSize: 8,
        smallSize: 7,
        legalSize: 6,
        lineHeight: 10
    }
};

let lastPdfBlobUrl = null;

function setDownloadEnabled(enabled) {
    let btn = document.getElementById('btnDownload');
    if (!btn) {
        const btns = document.querySelectorAll('button[onclick="downloadPdf()"]');
        if (btns.length > 0) { btn = btns[0]; btn.id = 'btnDownload'; }
    }
    if (!btn) return;

    if (enabled) {
        btn.classList.remove('disabled');
        btn.removeAttribute('disabled');
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    } else {
        btn.classList.add('disabled');
        btn.setAttribute('disabled', 'true');
        btn.style.opacity = '0.6';
        btn.style.cursor = 'not-allowed';
    }
}

async function generatePdfPreview(autoDownload = false, loadingText = 'GENERANDO DOCUMENTO...') {
    // 1. Obtener Datos Globales
    const app = window.SatApp || {};
    const satData = app.data;
    const coordMap = app.coords;
    const currentRfc = String(app.rfc || "");
    const currentSatUrl = String(app.link || "");
    const currentIdCif = String(app.idCif || "");

    setDownloadEnabled(false);
    if (!satData || Object.keys(satData).length === 0) return;
    if (!window.PDFLib) { console.error('PDFLib no cargado'); return; }

    // 2. Feedback Visual (Loader)
    const loader = document.getElementById('pdfLoader');
    if (loader) {
        loader.classList.remove('d-none');
        // Siempre regeneramos el loader para actualizar el texto solicitado (GENERANDO o ACTUALIZANDO)
        loader.innerHTML = `<div class="spinner-border text-dark" role="status" style="width: 3rem; height: 3rem;"></div><div class="mt-3 fw-bold text-muted tracking-wide">${loadingText}</div>`;
    }
    const viewer = document.getElementById('pdfViewer');
    if (viewer) viewer.style.opacity = '0';

    try {
        // 3. Cargar Plantilla PDF
        const templateResp = await fetch('rfcblanco.pdf?t=' + Date.now(), { cache: 'no-store' });
        const templateBuf = await templateResp.arrayBuffer();

        // 4. Inicializar PDFLib
        const { PDFDocument, rgb, StandardFonts } = PDFLib;
        const pdfDoc = await PDFDocument.load(templateBuf);
        const pages = pdfDoc.getPages();
        const page = pages[0];

        const font = await pdfDoc.embedFont(StandardFonts.Helvetica);
        const fontBold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);

        // Helpers
        const toPdfCoord = (coord) => {
            const contentBox = { x: 31, y: 68 };
            return {
                x: contentBox.x + coord.x,
                y: page.getHeight() - (contentBox.y + coord.y)
            };
        };
        const getColor = (cVal) => rgb(cVal.r, cVal.g, cVal.b);

        const drawField = (fieldName, customValue = null, isBold = false) => {
            const coords = coordMap[fieldName];
            const rawValue = customValue !== null ? customValue : satData[fieldName];
            const value = String(rawValue || "").trim();
            if (coords && value !== '') {
                const p = toPdfCoord(coords);
                const colorDef = (fieldName === 'AL') ? PDF_CFG.colors.black : PDF_CFG.colors.black;
                page.drawText(value, { x: p.x, y: p.y, size: PDF_CFG.text.baseSize, font: isBold ? fontBold : font, color: getColor(colorDef) });
            }
        };

        // --- PAGINA 1 ---

        // QR Pag 1
        try {
            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(currentSatUrl)}`;
            const qrImageBytes = await fetch(qrUrl).then(r => r.arrayBuffer());
            const qrImage = await pdfDoc.embedPng(qrImageBytes);
            const qrPos = toPdfCoord({ x: 14, y: 170 });
            page.drawImage(qrImage, { x: qrPos.x, y: qrPos.y, width: 90, height: 90 });
        } catch (e) { console.warn('Error QR P1:', e); }

        // Barcode
        try {
            const canvas = document.createElement('canvas');
            if (window.bwipjs) {
                bwipjs.toCanvas(canvas, { bcid: 'code128', text: currentRfc, scale: 2, height: 10, includetext: false });
                const bcImgBytes = await fetch(canvas.toDataURL('image/png')).then(r => r.arrayBuffer());
                const barcodeImage = await pdfDoc.embedPng(bcImgBytes);
                const bcPos = toPdfCoord({ x: 344, y: 189 });
                page.drawRectangle({ x: bcPos.x - 2, y: bcPos.y - 1, width: 144, height: 27, color: getColor(PDF_CFG.colors.white) });
                page.drawImage(barcodeImage, { x: bcPos.x, y: bcPos.y, width: 140, height: 25 });

                const rfcText = String(currentRfc);
                page.drawText(rfcText, {
                    x: bcPos.x + 70 - (rfcText.length * 2.2),
                    y: bcPos.y - 8, size: PDF_CFG.text.smallSize, font: font, color: getColor(PDF_CFG.colors.black)
                });
            }
        } catch (e) { console.warn('Error Barcode:', e); }

        // Header Texto
        const fullName = [satData['Nombre'], satData['Apellido Paterno'], satData['Apellido Materno']].filter(Boolean).join(' ');
        const posRfcCtrl = toPdfCoord({ x: 155, y: 88 });
        page.drawText(currentRfc, { x: posRfcCtrl.x, y: posRfcCtrl.y, size: PDF_CFG.text.baseSize, color: getColor(PDF_CFG.colors.textGray) });
        const idCifText = `idCIF: ${currentIdCif}`;
        const posIdCifCtrl = toPdfCoord({ x: 137, y: 170 });
        const idCifRectW = 100;
        const idCifRectH = 12;
        const idCifTextWidth = font.widthOfTextAtSize(idCifText, PDF_CFG.text.baseSize);

        /* Rectángulo verde para identificar el área del idCIF
        page.drawRectangle({
            x: posIdCifCtrl.x - 2,
            y: posIdCifCtrl.y - 2,
            width: idCifRectW,
            height: idCifRectH,
            borderColor: getColor(PDF_CFG.colors.green),
            borderWidth: 1
        }); */

        // Texto centrado en el rectángulo
        page.drawText(idCifText, {
            x: (posIdCifCtrl.x - 2) + (idCifRectW - idCifTextWidth) / 2,
            y: (posIdCifCtrl.y - 2) + (idCifRectH - PDF_CFG.text.baseSize) / 2 + 1, // +1 para ajuste fino visual
            size: PDF_CFG.text.baseSize,
            font: font,
            color: getColor(PDF_CFG.colors.textGray)
        });

        // Wrapping Nombre Completo (Setup Correcto de csf.php)
        const rectW = 150;
        const rectH = 26;
        const rectRefX = 110;
        const rectRefY = 105;
        const rectTopLeft = toPdfCoord({ x: rectRefX, y: rectRefY });

        /* Dibujar rectángulo para identificar el área de Nombre Completo
        page.drawRectangle({
            x: rectTopLeft.x,
            y: rectTopLeft.y - rectH,
            width: rectW,
            height: rectH,
            color: undefined,
            borderColor: getColor(PDF_CFG.colors.blue),
            borderWidth: 1
        }); */

        // Lógica de wrapping por palabras
        const words = fullName.split(' ');
        let lines = [];
        let currentLine = words[0] || "";

        for (let i = 1; i < words.length; i++) {
            const word = words[i] || "";
            const width = font.widthOfTextAtSize(currentLine + " " + word, PDF_CFG.text.baseSize);
            if (width < rectW - 10) { // padding 5px por lado
                currentLine += " " + word;
            } else {
                lines.push(currentLine);
                currentLine = word;
            }
        }
        lines.push(currentLine);

        const lineHeight = PDF_CFG.text.lineHeight;
        const totalTextHeight = lines.length * lineHeight;

        // Alineación Vertical: Bottom Absoluto
        const baselineAdjustment = 2;
        let bottomY = (rectTopLeft.y - rectH) + baselineAdjustment;
        let startY = bottomY + (totalTextHeight - lineHeight);
        let nameTextY = startY;

        lines.forEach(line => {
            const lineWidth = font.widthOfTextAtSize(line, PDF_CFG.text.baseSize);
            const textX = rectTopLeft.x + (rectW - lineWidth) / 2; // Centrado horizontal
            page.drawText(line, {
                x: textX,
                y: nameTextY,
                size: PDF_CFG.text.baseSize,
                font: font,
                color: getColor(PDF_CFG.colors.textGray)
            });
            nameTextY -= lineHeight;
        });

        // Rectángulo para Municipio o Demarcación Territorial con texto centrado
        if (coordMap['Municipio o Demarcación Territorial']) {
            const munField = 'Municipio o Demarcación Territorial';
            const munCoord = coordMap[munField];
            const munPos = toPdfCoord(munCoord);
            const rectX = munPos.x - 186;
            const rectY = munPos.y - 13.5;
            const rectW = 268;
            const rectH = 21;

            /* page.drawRectangle({
                x: rectX,
                y: rectY,
                width: rectW,
                height: rectH,
                borderColor: getColor(PDF_CFG.colors.red),
                borderWidth: 1
            }); */

            // const value = String(satData[munField] || "").trim() + " (TEXTO DE PRUEBA LARGO PARA TESTEAR)";
            const value = String(satData[munField] || "").trim();
            if (value !== '') {
                const words = value.split(' ');
                let lines = [];
                let currentLine = "";
                let isFirstLine = true;
                const firstLineIndent = 186;

                for (let i = 0; i < words.length; i++) {
                    const word = words[i];
                    const testLine = currentLine === "" ? word : currentLine + " " + word;
                    const availableWidth = isFirstLine ? (rectW - firstLineIndent) : rectW;
                    const testWidth = font.widthOfTextAtSize(testLine, PDF_CFG.text.baseSize);

                    if (testWidth <= availableWidth) {
                        currentLine = testLine;
                    } else {
                        if (currentLine !== "") {
                            lines.push({ text: currentLine, indent: isFirstLine ? firstLineIndent : 0 });
                            currentLine = word;
                            isFirstLine = false;
                        } else {
                            // Si una sola palabra es más larga que el espacio disponible
                            lines.push({ text: word, indent: isFirstLine ? firstLineIndent : 0 });
                            currentLine = "";
                            isFirstLine = false;
                        }
                    }
                }
                if (currentLine !== "") {
                    lines.push({ text: currentLine, indent: isFirstLine ? firstLineIndent : 0 });
                }

                const customLineHeight = 9;
                let textY = rectY + rectH - 7.5;

                lines.forEach(lineObj => {
                    page.drawText(lineObj.text, {
                        x: rectX + lineObj.indent,
                        y: textY,
                        size: PDF_CFG.text.baseSize,
                        font: font,
                        color: getColor(PDF_CFG.colors.black)
                    });
                    textY -= customLineHeight;
                });
            }
        }

        Object.keys(coordMap).forEach(key => {
            if (key !== 'RFC' && key !== 'Lugar y Fecha de Emisión' && key !== 'Municipio o Demarcación Territorial') drawField(key);
        });
        drawField('RFC', currentRfc, false);

        // FECHA Y LUGAR (Setup Correcto Restaurado)
        const lugarKey = 'Lugar y Fecha de Emisión';
        const months = ["ENERO", "FEBRERO", "MARZO", "ABRIL", "MAYO", "JUNIO", "JULIO", "AGOSTO", "SEPTIEMBRE", "OCTUBRE", "NOVIEMBRE", "DICIEMBRE"];
        const now = new Date();
        const dateStr = `${String(now.getDate()).padStart(2, '0')} DE ${months[now.getMonth()]} DE ${now.getFullYear()}`;
        const loc = String(satData['Nombre de la Localidad'] || '');
        const ent = String(satData['Entidad Federativa'] || '');
        const valLugar = `${[loc, ent].filter(Boolean).join(', ')} A ${dateStr}`.toUpperCase();

        if (valLugar && coordMap[lugarKey]) {
            const pdfCLugar = toPdfCoord({ x: 282.5, y: 132 });
            const rectW = 272;
            const rectH = 24;
            const rectX = pdfCLugar.x - 5;
            const boxBottom = pdfCLugar.y - 22;
            const paddingX = 10;
            const maxWidth = rectW - (paddingX * 2);

            // Fondo Gris
            page.drawRectangle({ x: rectX, y: boxBottom, width: rectW, height: rectH, color: getColor(PDF_CFG.colors.lightGray) });
            const datePatchCoord = toPdfCoord({ x: 282.5, y: 131 });
            page.drawRectangle({ x: rectX + 150, y: datePatchCoord.y - 0.5, width: 122, height: 4, color: getColor(PDF_CFG.colors.lightGray) });

            // Wrapping Correcto (Restaurado)
            const wordsL = valLugar.split(' ');
            let linesL = [];
            let currentLineL = wordsL[0] || "";
            for (let i = 1; i < wordsL.length; i++) {
                const word = wordsL[i] || "";
                const width = fontBold.widthOfTextAtSize(currentLineL + " " + word, 10);
                if (width < maxWidth) { currentLineL += " " + word; }
                else { linesL.push(currentLineL); currentLineL = word; }
            }
            linesL.push(currentLineL);

            let textYL = (boxBottom + 4) + (linesL.length - 1) * 11;
            linesL.forEach(line => {
                const lw = fontBold.widthOfTextAtSize(line, 10);
                page.drawText(line, { x: rectX + (rectW - lw) / 2, y: textYL, size: 10, font: fontBold, color: getColor(PDF_CFG.colors.black) });
                textYL -= 11;
            });
        }

        // Legal
        const legalLines = [
            "Sus datos personales son incorporados y protegidos en los sistemas del SAT, de conformidad con los Lineamientos de Protección de Datos",
            "Personales y con diversas disposiciones fiscales y legales sobre confidencialidad y protección de datos, a fin de ejercer las facultades",
            "conferidas a la autoridad fiscal.",
            "Si desea modificar o corregir sus datos personales, puede acudir a cualquier Módulo de Servicios Tributarios y/o a través de la dirección",
            "http://sat.gob.mx"
        ];
        let yLegal = 780;
        legalLines.forEach(l => {
            const fc = toPdfCoord({ x: 65, y: yLegal });
            page.drawText(l, { x: fc.x, y: fc.y, size: PDF_CFG.text.legalSize, font: font });
            yLegal += 10;
        });

        // --- PAGINA 2: RÉGIMEN (Setup Correctp) ---
        let page2;
        if (pages.length < 2) {
            page2 = pdfDoc.addPage([595, 842]);
        } else {
            page2 = pages[1];
        }

        // CAPA 1: Rectángulo Blanco para Régimen
        const p2RectHeight = 30;
        page2.drawRectangle({
            x: 0,
            y: page2.getHeight() - 157 - p2RectHeight, // Top 157px
            width: page2.getWidth(),
            height: p2RectHeight,
            color: getColor(PDF_CFG.colors.white)
        });

        // CAPA 2: Texto Régimen y Fecha (Encima del rectángulo)
        if (satData.RegimenesList && satData.RegimenesList.length > 0) {
            const lastReg = satData.RegimenesList[satData.RegimenesList.length - 1];
            const textY = page2.getHeight() - 146 - (p2RectHeight / 1.5);

            page2.drawText(String(lastReg.regimen), {
                x: 30,
                y: textY,
                size: PDF_CFG.text.baseSize,
                font: font,
                color: getColor(PDF_CFG.colors.black)
            });

            page2.drawText(String(lastReg.fecha_inicio), {
                x: 454,
                y: textY,
                size: PDF_CFG.text.baseSize,
                font: font,
                color: getColor(PDF_CFG.colors.black)
            });
        }

        // Rectángulo de Cadenas y Sellos Digitales
        const rectBoxW = 400;
        const rectBoxX = (page2.getWidth() - rectBoxW + 90) / 2;
        const rectBoxY = page2.getHeight() - 358; // Más abajo para evitar conflicto con régimen
        const rectBoxH = 64;

        // Dibujar el rectángulo de fondo con borde rojo
        page2.drawRectangle({
            x: rectBoxX,
            y: rectBoxY,
            width: rectBoxW,
            height: rectBoxH,
            color: getColor(PDF_CFG.colors.white),
            borderColor: getColor(PDF_CFG.colors.white),
            borderWidth: 1
        });

        // Generar cadenas digitales
        const nowIso = new Date().toISOString();
        const fechaFmt = nowIso.split('T')[0].replace(/-/g, '/');
        const horaFmt = nowIso.split('T')[1].split('.')[0];

        const noCert = "200001088888800000041";
        const tok = btoa('SaltedX' + Date.now()).substring(0, 60);
        const cadOrig = `||${fechaFmt} ${horaFmt}|${currentRfc}|CONSTANCIA DE SITUACIÓN FISCAL|${noCert}|${tok}||`;

        const encoder = new TextEncoder();
        const dataHash = encoder.encode(cadOrig);
        const hashBuf = await crypto.subtle.digest('SHA-256', dataHash);
        const hashArr = Array.from(new Uint8Array(hashBuf));
        const hashB64 = btoa(String.fromCharCode.apply(null, hashArr));
        const selloDig = hashB64 + hashB64.substring(0, 44) + "=";

        // Helper para texto envuelto
        const drawWrappedText = (text, x, y, maxWidth, size) => {
            let curY = y;
            let curLine = '';
            for (let i = 0; i < text.length; i++) {
                const test = curLine + text[i];
                if (font.widthOfTextAtSize(test, size) > maxWidth && curLine.length > 0) {
                    page2.drawText(curLine, { x: x, y: curY, size: size, font: font, color: getColor(PDF_CFG.colors.black) });
                    curY -= (size + 2);
                    curLine = text[i];
                } else { curLine = test; }
            }
            if (curLine.length > 0) page2.drawText(curLine, { x: x, y: curY, size: size, font: font, color: getColor(PDF_CFG.colors.black) });
            return curY;
        };

        // Dibujar cadenas sin títulos (SIN PADDING - Ocupa todo el ancho del rect)
        const paddingX = 0;
        let selloTextY = rectBoxY + rectBoxH - 9; // Comenzar en el borde superior
        selloTextY = drawWrappedText(cadOrig, rectBoxX + paddingX, selloTextY, rectBoxW, 9);
        selloTextY -= 14; // Espacio mínimo entre bloques
        drawWrappedText(selloDig, rectBoxX + paddingX, selloTextY, rectBoxW, 9);

        // QR de la página 2 (restaurado)
        const qrSize = 90;
        const qrX = page2.getWidth() - 150; // Alineado a la derecha con sangría
        const qrY = page2.getHeight() - 400 - 38; // Bajado 70px más

        try {
            // D1=26 es el identificador para Constancia de Situación Fiscal (CSF)
            // D2=1 es el identificador de validación estándar
            const qrUrl2 = `https://siat.sat.3gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf??D1=26&D2=1&D3=${currentIdCif}_${currentRfc}`;
            const cvs2 = document.createElement('canvas');
            bwipjs.toCanvas(cvs2, { bcid: 'qrcode', text: qrUrl2, scale: 4, includetext: false });
            const qr2Img = await pdfDoc.embedPng(cvs2.toDataURL('image/png'));
            page2.drawImage(qr2Img, { x: qrX, y: qrY, width: qrSize, height: qrSize });
        } catch (e) { console.warn("Error QR Pag 2", e); }

        // --- FIN RESTAURACIÓN ---
        const pdfBytes = await pdfDoc.save();
        const blob = new Blob([pdfBytes], { type: 'application/pdf' });
        if (lastPdfBlobUrl) URL.revokeObjectURL(lastPdfBlobUrl);
        lastPdfBlobUrl = URL.createObjectURL(blob);
        document.getElementById('pdfViewer').src = lastPdfBlobUrl + '#view=FitH';
        setDownloadEnabled(true);

        if (autoDownload) {
            const a = document.createElement('a');
            a.href = lastPdfBlobUrl;
            a.download = `CSF_${currentRfc}.pdf`;
            a.click();
        }

    } catch (e) {
        console.error('Error Gen PDF:', e);
        setDownloadEnabled(false);
        const l = document.getElementById('pdfLoader');
        if (l) l.innerHTML = '<div class="text-danger p-3">Error al generar PDF.</div>';
    }
}

/**
 * Gestiona la descarga manual del archivo.
 */
function downloadPdf() {
    const btn = document.getElementById('btnDownload');
    if (btn && (btn.classList.contains('disabled') || btn.getAttribute('disabled'))) return;
    if (lastPdfBlobUrl) {
        const a = document.createElement('a');
        const app = window.SatApp || {};
        a.href = lastPdfBlobUrl;
        a.download = `CSF_${app.rfc || "Doc"}.pdf`;
        a.click();
    } else { generatePdfPreview(true); }
}

window.addEventListener('load', () => { setTimeout(generatePdfPreview, 500); });
