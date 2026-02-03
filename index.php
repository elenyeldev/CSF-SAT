<?php
// Configuración detallada de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Headers para desarrollo
header('X-Developer-Mode: Debug');

function forceNoCache() {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
}
function forceClearCaches() {
    clearstatcache(true);
    if (function_exists('apcu_clear_cache')) { @apcu_clear_cache(); }
    elseif (function_exists('apc_clear_cache')) { @apc_clear_cache(); @apc_clear_cache('user'); }
    if (function_exists('opcache_reset')) { @opcache_reset(); }
}
forceNoCache();

// === Normalización y extracción defensiva ===
function normalize_sat_html(string $html): string {
    $html = preg_replace('/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/iu', '', $html);
    $html = preg_replace('/<\s*(br|p|li|td|tr|div|h[1-6])\b[^>]*>/iu', "\n", $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    $text = preg_replace("/\n{2,}/", "\n", $text);
    // Remover bloques JS conocidos que ensucian el texto
    $text = preg_replace('/\$\s*\(function\s*\(\)\s*\{[\s\S]*?\}\s*\)\s*;?/u', '', $text);
    $text = preg_replace('/PrimeFaces\.cw\([^\)]*\);\s*/ui', '', $text);
    return trim($text);
}
function extract_label_value(string $text, string $html, array $labels): string {
    foreach ($labels as $lbl) {
        $patternText = '/^\s*' . preg_quote($lbl, '/') . '\s*:\s*(.*?)\s*$/imu';
        if (preg_match($patternText, $text, $m)) { return trim($m[1]); }
        if ($html !== '') {
            $patternHtml = '/'.preg_quote($lbl, '/').'\s*:\s*(?:<\/?\w+[^>]*>\s*)*([^<\n]+)/imu';
            if (preg_match($patternHtml, $html, $mh)) { return trim(html_entity_decode($mh[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
        }
    }
    return '';
}
function sanitize_value(string $value, array $knownLabels): string {
    $v = $value;
    foreach ($knownLabels as $lbl) {
        $pos = mb_stripos($v, $lbl . ':');
        if ($pos !== false) { $v = trim(mb_substr($v, 0, $pos)); }
    }
    $v = preg_replace('/\bFecha\s+de\s+alta\s*:\s*/iu', '', $v);
    return trim($v);
}
function extract_sat_data($html): array {
    $text = ($html !== false && $html !== null) ? normalize_sat_html($html) : '';
    $labels = [
        'CURP' => ['CURP'],
        'Nombre' => ['Nombre', 'Nombre(s)', 'Denominación o Razón Social'],
        'Apellido Paterno' => ['Apellido Paterno'],
        'Apellido Materno' => ['Apellido Materno'],
        'Fecha de Inicio de operaciones' => ['Fecha de Inicio de operaciones'],
        'Situación del contribuyente' => ['Situación del contribuyente'],
        'Fecha del último cambio de situación' => ['Fecha del último cambio de situación'],
        'Entidad Federativa' => ['Entidad Federativa'],
        'Municipio o delegación' => ['Municipio o delegación'],
        'Colonia' => ['Colonia'],
        'Tipo de vialidad' => ['Tipo de vialidad'],
        'Nombre de la vialidad' => ['Nombre de la vialidad'],
        'Número exterior' => ['Número exterior'],
        'Número interior' => ['Número interior'],
        'CP' => ['CP'],
        'Correo electrónico' => ['Correo electrónico'],
        'AL' => ['AL'],
        'Régimen' => ['Régimen'],
        'Nombre de la Localidad' => ['Nombre de la Localidad'],
        'Entre Calle' => ['Entre Calle'],
    ];
    $data = [];
    foreach ($labels as $key => $choices) {
        $data[$key] = extract_label_value($text, $html, $choices);
    }
    $allLabels = [];
    foreach ($labels as $choices) {
        foreach ($choices as $lbl) { $allLabels[] = $lbl; }
    }
    foreach ($data as $k => $v) { $data[$k] = sanitize_value($v, $allLabels); }
    return $data;
}
function fetchHtml(string $url, ?array &$debug = null) {
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: es-MX,es;q=0.9',
        'Connection: keep-alive',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_REFERER => 'https://siat.sat.gob.mx/',
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT:!DH',
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp !== false && $resp !== '' && $status === 200) {
            if ($debug !== null) { $debug['status'] = $status; }
            return $resp;
        }
        // Fallback inseguro: se registra explícitamente
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_REFERER => 'https://siat.sat.gob.mx/',
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT:!DH',
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ]);
        $resp2 = curl_exec($ch);
        $err2 = curl_error($ch);
        $status2 = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($debug !== null) {
            $debug['status'] = $status2;
            $debug['error'] = $err ?: $err2;
            $debug['fallback_insecure'] = true;
            $debug['fallback_reason'] = 'SSL estricto falló o respuesta vacía';
        }
        return $resp2;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: text/html\r\nAccept-Language: es-MX,es;q=0.9\r\n",
            'timeout' => 15,
        ],
        'ssl' => [
            'crypto_method' => defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0,
            'ciphers' => 'DEFAULT:!DH',
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp !== false && $resp !== '') { return $resp; }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: text/html\r\nAccept-Language: es-MX,es;q=0.9\r\n",
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'crypto_method' => defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0,
            'ciphers' => 'DEFAULT:!DH',
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($debug !== null) {
        $debug['fallback_insecure'] = true;
        $debug['fallback_reason'] = 'file_get_contents con SSL relajado';
    }
    return $resp;
}
// Función nativa para generar código QR simple (sin librerías externas)

// Salida JSON pura cuando se solicita ?json=1 con idcif y rfc
if (!empty($_GET['json']) && $_GET['json'] === '1' && !empty($_GET['idcif']) && !empty($_GET['rfc'])) {
    $idcif = trim($_GET['idcif']);
    $rfc   = trim($_GET['rfc']);
    $baseUrl = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=';
    $link = $baseUrl . $idcif . '_' . $rfc;
    $debug = [];
    $html = fetchHtml($link, $debug);
    $data = extract_sat_data($html);
    $coords = getPdfCoordMap();
    // Faltantes
    $faltantes = [];
    foreach ($data as $campo => $valor) {
        if ($valor === '' || $valor === null) {
            if (isset($coords[$campo])) { $faltantes[] = ['campo' => $campo, 'x' => $coords[$campo]['x'], 'y' => $coords[$campo]['y']]; }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode(['rfc' => $rfc, 'data' => $data, 'faltantes' => $faltantes], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consulta SAT - Gobierno de México</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root{
      --mx-guinda:#9D2449; --mx-guinda-osc:#7F1D3D; --mx-oro:#B38E5D; --mx-verde:#0A8F32;
    }
    .brand-gradient{ background:linear-gradient(90deg,var(--mx-guinda),var(--mx-guinda-osc)); }
    .brand-btn{ background-color:var(--mx-verde); color:#fff; }
    .brand-btn:hover{ background-color:#087428; color:#fff; }
    .brand-accent{ color:var(--mx-oro); }
    .card-shadow{ box-shadow:0 10px 25px rgba(0,0,0,.08); }
  </style>
</head>
<body class="bg-neutral-50 min-h-screen">
  <header class="brand-gradient text-white">
    <div class="container py-4">
      <h1 class="h3 m-0 fw-bold tracking-wide">Consulta de RFC — Gobierno de México</h1>
      <div class="brand-accent">Validador QR del SAT</div>
    </div>
  </header>
  <div class="container py-4">
  <h1 class="h4 mb-3">Generar URL del SAT</h1>

  <form method="get" class="row g-3">
    <div class="col-12 col-md-4">
      <label class="form-label">ID CIF</label>
      <input type="text" name="idcif" class="form-control" required>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label">RFC</label>
      <input type="text" name="rfc" class="form-control" required>
    </div>
    <div class="col-12 col-md-4 d-flex align-items-end">
      <button type="submit" class="btn brand-btn w-100 py-2">Generar link y extraer datos</button>
    </div>
  </form>

  <hr class="my-4">

  <?php
  // Modo descarga directa: no guarda en disco, entrega el PDF en memoria
  if (!empty($_GET['dl']) && $_GET['dl'] === '1' && !empty($_GET['idcif']) && !empty($_GET['rfc'])) {
      $idcif = trim($_GET['idcif']);
      $rfc   = trim($_GET['rfc']);
      $baseUrl = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=';
      $link = $baseUrl . $idcif . '_' . $rfc;
      // Reutilizamos funciones más abajo para extracción
  }
  if (!empty($_GET['idcif']) && !empty($_GET['rfc'])) {
      $idcif = trim($_GET['idcif']);
      $rfc   = trim($_GET['rfc']);

      // Base oficial del validador QR del SAT
      $baseUrl = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=';

      // D3 = idcif_rfc (con guion bajo entre ambos)
      $link = $baseUrl . $idcif . '_' . $rfc;

      $debug = [];
      $html = fetchHtml($link, $debug);
      $data = extract_sat_data($html);
      function pdf_escape($s) {
          $s = str_replace('\\', '\\\\', $s);
          $s = str_replace('(', '\\(', $s);
          $s = str_replace(')', '\\)', $s);
          $s = str_replace("\r", ' ', $s);
          $s = str_replace("\n", ' ', $s);
          return $s;
      }
      // Función nativa simple para generar código QR básico (sin librerías externas)
      function generateSimpleQR($url, $size = 3) {
          // Implementación básica de código QR usando GD nativo
          $qrSize = 21; // Tamaño mínimo para QR versión 1
          $qr = imagecreatetruecolor($qrSize, $qrSize);
          
          // Colores: blanco para fondo, negro para módulos
          $white = imagecolorallocate($qr, 255, 255, 255);
          $black = imagecolorallocate($qr, 0, 0, 0);
          
          // Fondo blanco
          imagefill($qr, 0, 0, $white);
          
          // Patrón básico de prueba (en producción se usaría un algoritmo real)
          // Esto es solo un placeholder - para QR reales necesitarías implementar
          // el algoritmo completo de codificación QR o usar un servicio externo
          for ($i = 0; $i < $qrSize; $i += 3) {
              for ($j = 0; $j < $qrSize; $j += 3) {
                  if (($i + $j) % 6 == 0) {
                      imagefilledrectangle($qr, $i, $j, $i+2, $j+2, $black);
                  }
              }
          }
          
          // Convertir a base64
          ob_start();
          imagepng($qr);
          $imageData = ob_get_clean();
          imagedestroy($qr);
          
          return 'data:image/png;base64,' . base64_encode($imageData);
      }

      // Alias para compatibilidad
      function generateQRImage($url, $size = 200) {
          return generateSimpleQR($url, $size);
      }
      
      function generateQRBase64($url, $size = 200) {
          return generateSimpleQR($url, $size);
      }

      // Devuelve el mapa de coordenadas fijas (x,y) de cada campo en la plantilla rfcblanco.pdf
      function getPdfCoordMap() {
          return [
              // ===== DATOS DE IDENTIFICACIÓN =====
              'RFC' => ['x' => 237, 'y' => 565],
              'CURP' => ['x' => 237, 'y' => 547],
              'Nombre' => ['x' => 237, 'y' => 529],
              'Apellido Paterno' => ['x' => 237, 'y' => 506],
              'Apellido Materno' => ['x' => 237, 'y' => 483],
              'Fecha de Inicio de operaciones' => ['x' => 237, 'y' => 458],
              'Situación del contribuyente' => ['x' => 237, 'y' => 427],
              'Fecha del último cambio de situación' => ['x' => 237, 'y' => 405],
              'Nombre Comercial' => ['x' => 237, 'y' => 382],
              'Régimen' => ['x' => 237, 'y' => 360],
              
              // ===== DOMICILIO FISCAL =====
              'CP' => ['x' => 238, 'y' => 325],
              'Tipo de vialidad' => ['x' => 130, 'y' => 329],
              'Nombre de la vialidad' => ['x' => 116, 'y' => 228],
              'Número exterior' => ['x' => 360, 'y' => 311],
              'Número interior' => ['x' => 0, 'y' => 206],
              'Colonia' => ['x' => 180, 'y' => 293],
              'Nombre de la Localidad' => ['x' => 132, 'y' => 184],
              'Municipio o delegación' => ['x' => 360, 'y' => 270],
              'Entidad Federativa' => ['x' => 100, 'y' => 247],
              'Entre Calle' => ['x' => 360, 'y' => 247],
              'Correo electrónico' => ['x' => 130, 'y' => 224],
              'AL' => ['x' => 360, 'y' => 224],
          ];
      }
      function getContentBox() {
          return ['x' => 31, 'y' => 68, 'width' => 550, 'height' => 690];
      }
      function toPdfCoordFromTop($x, $y, $pageHeight = 842, $box = null) {
          $bx = is_array($box) && isset($box['x']) ? $box['x'] : 0;
          $by = is_array($box) && isset($box['y']) ? $box['y'] : 0;
          return ['x' => ($bx + $x), 'y' => ($pageHeight - ($by + $y))];
      }
      function generate_csf_pdf_bytes($rfc, $data) {
          $objects = [];
          $pdf = "%PDF-1.4\n";
          $offsets = [];
          $addObj = function($obj) use (&$objects) { $objects[] = $obj; };
          $addObj("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");
          $addObj("2 0 obj\n<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 2 >>\nendobj\n");
          $addObj("4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n");
          
          // Generar link del SIAT
          $baseUrl = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=';
          $siatLink = $baseUrl . $_GET['idcif'] . '_' . $rfc;
          
          // Obtener coordenadas fijas
          $coords = getPdfCoordMap();
          $box = getContentBox();
          // Página 1: Contenido principal (texto únicamente)
          $title = "Cédula de Identificación Fiscal — RFC ".strtoupper($rfc);
          $ctitle = toPdfCoordFromTop($coords['RFC']['x'], $coords['RFC']['y'], 842, $box);
          $stream1 = "BT /F1 9 Tf ".$ctitle['x']." ".$ctitle['y']." Td (".pdf_escape($title).") Tj ET\n";
          
          // Campos principales (sección de identificación)
          $mainFields = [
              'CURP','Nombre','Apellido Paterno','Apellido Materno',
              'Fecha de Inicio de operaciones','Situación del contribuyente','Fecha del último cambio de situación','Régimen'
          ];
          
          $addressCombinations = [];
          
          // Escribir campos principales (coordenadas capturadas arriba-izquierda convertidas a PDF)
          foreach ($mainFields as $field) {
              $val = isset($data[$field]) ? $data[$field] : '';
              if ($val !== '') {
                  $c = toPdfCoordFromTop($coords[$field]['x'], $coords[$field]['y'], 842, $box);
                  $stream1 .= "BT /F1 8 Tf ".$c['x']." ".$c['y']." Td (".pdf_escape($val).") Tj ET\n";
              }
          }
          
          $individualAddressFields = ['CP', 'Tipo de vialidad', 'Nombre de la vialidad', 'Número exterior', 'Número interior', 'Colonia', 'Nombre de la Localidad', 'Municipio o delegación', 'Entidad Federativa', 'Entre Calle', 'Correo electrónico', 'AL'];
          foreach ($individualAddressFields as $field) {
              $val = isset($data[$field]) ? $data[$field] : '';
              if ($val !== '') {
                  $c = toPdfCoordFromTop($coords[$field]['x'], $coords[$field]['y'], 842, $box);
                  $fs = ($field === 'CP') ? 7 : 8;
                  $stream1 .= "BT /F1 ".$fs." Tf ".$c['x']." ".$c['y']." Td (".pdf_escape($val).") Tj ET\n";
              }
          }
          
          // Escribir combinaciones de campos de dirección
          foreach ($addressCombinations as $combo) {
              $vals = [];
              foreach ($combo as $field) {
                  $val = isset($data[$field]) ? $data[$field] : '';
                  if ($val !== '') {
                      $vals[] = $val;
                  }
              }
              if (!empty($vals)) {
                  $combinedText = implode(' ', $vals);
                  // Usar coordenadas del primer campo de la combinación
                  $firstField = $combo[0];
                  $c = toPdfCoordFromTop($coords[$firstField]['x'], $coords[$firstField]['y'], 842, $box);
                  $stream1 .= "BT /F1 8 Tf ".$c['x']." ".$c['y']." Td (".pdf_escape($combinedText).") Tj ET\n";
              }
          }
          
          // Omitir imágenes: no hay XObjects definidos
          
          $len1 = strlen($stream1);
          $addObj("5 0 obj\n<< /Length ".$len1." >>\nstream\n".$stream1."endstream\nendobj\n");
          $addObj("3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n");
          
          // Página 2: QR grande en esquina superior derecha
          $stream2 = "";
          // Sin imágenes en página 2
          
          $len2 = strlen($stream2);
          $addObj("7 0 obj\n<< /Length ".$len2." >>\nstream\n".$stream2."endstream\nendobj\n");
          $addObj("6 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 7 0 R >>\nendobj\n");
          $pos = strlen($pdf);
          foreach ($objects as $obj) {
              $offsets[] = $pos;
              $pdf .= $obj;
              $pos = strlen($pdf);
          }
          $xrefPos = strlen($pdf);
          $pdf .= "xref\n0 ".(count($objects)+1)."\n";
          $pdf .= "0000000000 65535 f \n";
          foreach ($offsets as $off) {
              $pdf .= sprintf("%010d 00000 n \n", $off);
          }
          $pdf .= "trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xrefPos."\n%%EOF";
          return $pdf;
      }
      // Modo JSON: devolver rfc, data y campos faltantes con coordenadas
      if (!empty($_GET['json']) && $_GET['json'] === '1') {
          $coords = getPdfCoordMap();
          $faltantes = [];
          foreach ($data as $campo => $valor) {
              if ($valor === '' || $valor === null) {
                  if (isset($coords[$campo])) {
                      $faltantes[] = ['campo' => $campo, 'x' => $coords[$campo]['x'], 'y' => $coords[$campo]['y']];
                  }
              }
          }
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['rfc' => $rfc, 'data' => $data, 'faltantes' => $faltantes], JSON_UNESCAPED_UNICODE);
          exit;
      }
      // Si es solicitud de descarga directa, emitir PDF y terminar
      if (!empty($_GET['dl']) && $_GET['dl'] === '1') {
          forceClearCaches();
          forceNoCache();
          $bytes = generate_csf_pdf_bytes($rfc, $data);
          header('Content-Type: application/pdf');
          header('Content-Disposition: attachment; filename="'.strtoupper($rfc).'_'.gmdate('Ymd_His').'.pdf"');
          header('Content-Length: '.strlen($bytes));
          echo $bytes;
          exit;
      }
      // Generación local en memoria para descarga por JS
      $bytes = generate_csf_pdf_bytes($rfc, $data);
      $generated = ($bytes !== null && strlen($bytes) > 0);
      ?>
      <div class="border-top pt-3 mt-2">
        <div class="fw-semibold mb-2">Documento</div>
        <?php if ($generated): ?>
          <div id="genModal" class="modal fade" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header brand-gradient text-white">
                  <h5 class="modal-title">Generación exitosa</h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <p id="genModalMessage" class="m-0"></p>
                </div>
              </div>
            </div>
          </div>
          <script>
            (function(){
              const fullName = [<?= json_encode($data['Nombre']) ?>, <?= json_encode($data['Apellido Paterno']) ?>, <?= json_encode($data['Apellido Materno']) ?>].filter(Boolean).join(' ');
              const msg = 'rfc de Nombre completo: ' + fullName + ' generado correctamente';
              const payload = {
                rfc: <?= json_encode($rfc) ?>,
                data: <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>
              };
              const run = async function() {
                const el = document.getElementById('genModalMessage');
                if (el) el.textContent = msg;
                const modalEl = document.getElementById('genModal');
                if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                  const m = new bootstrap.Modal(modalEl);
                  m.show();
                  setTimeout(function(){ m.hide(); }, 3000);
                }
                // Generar PDF en el navegador usando rfcblanco.pdf como plantilla
                if (!window.PDFLib) { console.error('PDFLib no cargado'); return; }
                try {
                  const templateResp = await fetch('rfcblanco.pdf?t=' + Date.now(), { cache: 'no-store' });
                  const templateBuf = await templateResp.arrayBuffer();
                  const { PDFDocument, rgb, StandardFonts } = PDFLib;
                  const pdfDoc = await PDFDocument.load(templateBuf);
                  const pages = pdfDoc.getPages();
                  const page = pages[0];
                  const helv = await pdfDoc.embedFont(StandardFonts.Helvetica);
                  const contentBox = { x: 31, y: 68, width: 550, height: 690 };
                  const toPdfCoord = (coord, pg) => ({ x: contentBox.x + coord.x, y: pg.getHeight() - (contentBox.y + coord.y) });
                  // Coordenadas (origen abajo-izquierda) con caja de contenido
                  const cRFC = toPdfCoord({ x: 237, y: 315 }, page);
                  let yTop = cRFC.y;
                  page.drawText(String(payload.rfc).toUpperCase(), { x: cRFC.x, y: cRFC.y, size: 9, font: helv, color: rgb(0.1,0.1,0.1) });
                  if ((payload.data['CP'] || '').trim().length > 0) {
                    const c = toPdfCoord({ x: 238, y: 325 }, page);
                    page.drawText(String(payload.data['CP']), { x: c.x, y: c.y, size: 7, font: helv, color: rgb(0,0,0), maxWidth: 110 });
                  }
                  if ((payload.data['Nombre de la vialidad'] || '').trim().length > 0) {
                    const c = toPdfCoord({ x: 116, y: 228 }, page);
                    page.drawText(String(payload.data['Nombre de la vialidad']), { x: c.x, y: c.y, size: 8, font: helv, color: rgb(0,0,0) });
                  }
                  if ((payload.data['Número interior'] || '').trim().length > 0) {
                    const c = toPdfCoord({ x: 0, y: 206 }, page);
                    page.drawText(String(payload.data['Número interior']), { x: c.x, y: c.y, size: 8, font: helv, color: rgb(0,0,0) });
                  }
                  if ((payload.data['Nombre de la Localidad'] || '').trim().length > 0) {
                    const c = toPdfCoord({ x: 132, y: 184 }, page);
                    page.drawText(String(payload.data['Nombre de la Localidad']), { x: c.x, y: c.y, size: 8, font: helv, color: rgb(0,0,0) });
                  }
                  // Preparar dos bloques: principales y dirección/contacto aparte
                  const mainFields = [
                    'CURP','Nombre','Apellido Paterno','Apellido Materno',
                    'Fecha de Inicio de operaciones','Situación del contribuyente','Fecha del último cambio de situación','Régimen'
                  ];
                  const orderedAddr = [
                    { key: 'Número exterior' },
                    { key: 'Municipio o delegación' },
                    { key: 'Entidad Federativa' },
                    { key: 'Entre Calle' }
                  ];
                  // Offsets personalizados (px): positivos = bajar, negativos = subir
                  const yOffsets = {
                    'Nombre': 2,
                    'Apellido Paterno': 5,
                    'Apellido Materno': 10,
                    'Fecha de Inicio de operaciones': 12,
                    'Situación del contribuyente': 18,
                    'Fecha del último cambio de situación': 20
                  };
                  const startYMain = yTop - 22;
                  const startYAddr = yTop - 22;
                  const addrYOffset = 210;
                  let yMain = startYMain;
                  for (const key of mainFields) {
                    const valText = (payload.data[key] || '');
                    const off = (key in yOffsets) ? yOffsets[key] : 0;
                    page.drawText(valText, { x: 237, y: yMain - off, size: 8, font: helv, color: rgb(0,0,0) });
                    yMain -= 18;
                    if (yMain < 60) break;
                  }
                  let yAddr = startYAddr;
                  const dxMap = {
                    'Entidad Federativa': -30
                  };
                  const dyMap = {
                    'Entidad Federativa': 8
                  };
                  for (const item of orderedAddr) {
                    let line = '';
                    const val = payload.data[item.key] || '';
                    line = val;
                    let keyForOffsets = item.key ? item.key : '';
                    const dx = dxMap[keyForOffsets] || 0;
                    const dy = dyMap[keyForOffsets] || 0;
                    page.drawText(line, { x: 130 + dx, y: (yAddr - addrYOffset) + dy, size: 8, font: helv, color: rgb(0,0,0) });
                    yAddr -= 18;
                    if (yAddr < 60) break;
                  }
                  const outBytes = await pdfDoc.save();
                  const blob = new Blob([outBytes], { type: 'application/pdf' });
                  const blobUrl = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = blobUrl;
                  const fname = String(payload.rfc).toUpperCase() + '_' + Date.now() + '.pdf';
                  a.download = fname;
                  a.style.display = 'none';
                  document.body.appendChild(a);
                  a.click();
                  URL.revokeObjectURL(blobUrl);
                  a.remove();
                } catch (e) {
                  console.error('PDF generation error', e);
                }
              };
              if (document.readyState === 'complete') run();
              else window.addEventListener('load', run);
            })();
          </script>
        <?php else: ?>
          <div class="alert alert-danger">No fue posible generar el PDF.</div>
        <?php endif; ?>
      </div>
      <?php
      $any = false;
      foreach ($data as $v) { if ($v !== '') { $any = true; break; } }
      if ($html === false || $html === '') {
          echo '<div class="alert alert-danger mt-3">No se pudo obtener contenido del SAT.</div>';
          if (!empty($debug)) {
              echo '<div class="alert alert-warning">Detalle: ';
              if (isset($debug['status'])) { echo 'HTTP '.$debug['status'].' '; }
              if (!empty($debug['error'])) { echo htmlspecialchars($debug['error']); }
              echo '</div>';
          }
          if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
              echo '<div class="alert alert-warning">PHP no tiene cURL y tiene deshabilitado allow_url_fopen.</div>';
          }
      } elseif (!$any) {
          echo '<div class="alert alert-info mt-3">No se encontraron datos en el HTML devuelto.</div>';
      }
      } // fin if (!empty(...))
      ?>
    <script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    </body>
</html>
