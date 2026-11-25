<?php
/**
 * CONFIGURACIÓN E INTEGRACIÓN DE IA — GROQ API
 * GROUP_CEA — Instituto Jurídico, Académico y Empresarial
 */

if (!defined('GROQ_ENDPOINT')) {
    define('GROQ_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions');
}
if (!defined('GROQ_MODELO_DEFECTO')) {
    define('GROQ_MODELO_DEFECTO', 'llama-3.3-70b-versatile');
}

/**
 * Obtiene la configuración actual de Groq (API Key y Modelo)
 */
function obtenerGroqConfig(): array
{
    $keyFile = __DIR__ . '/groq_key.local.php';
    $apiKey = '';
    $modelo = GROQ_MODELO_DEFECTO;

    if (file_exists($keyFile)) {
        try {
            $datosLocal = include $keyFile;
            if (is_array($datosLocal)) {
                $apiKey = trim((string) ($datosLocal['api_key'] ?? ''));
                if (!empty($datosLocal['modelo'])) {
                    $modelo = trim((string) $datosLocal['modelo']);
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (empty($apiKey)) {
        $apiKey = trim((string) (getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '')));
    }

    return [
        'api_key' => $apiKey,
        'modelo' => $modelo,
        'disponible' => !empty($apiKey),
        'origen' => !empty($apiKey) ? 'groq' : 'local'
    ];
}

/**
 * Guarda o actualiza la configuración de Groq en archivo local protegido
 */
function guardarGroqConfig(string $apiKey, string $modelo = GROQ_MODELO_DEFECTO): bool
{
    $keyFile = __DIR__ . '/groq_key.local.php';
    $apiKey = trim($apiKey);
    $modelo = trim($modelo) ?: GROQ_MODELO_DEFECTO;

    $contenido = "<?php\n// Configuración local de Groq API para GROUP_CEA\nreturn " . var_export([
        'api_key' => $apiKey,
        'modelo' => $modelo,
        'actualizado_en' => date('Y-m-d H:i:s'),
    ], true) . ";\n";

    return (bool) file_put_contents($keyFile, $contenido, LOCK_EX);
}

/**
 * Realiza la consulta a la API de Groq
 *
 * @param array $mensajes Lista de mensajes con roles ('system', 'user', 'assistant')
 * @param array $opciones Parámetros adicionales (temperatura, max_tokens, modelo)
 * @return array Resultado con 'exito', 'respuesta' o 'error'
 */
function consultarGroqAPI(array $mensajes, array $opciones = []): array
{
    $config = obtenerGroqConfig();
    $apiKey = $config['api_key'];

    if (empty($apiKey)) {
        return [
            'exito' => false,
            'error' => 'No hay una API Key de Groq configurada.',
            'codigo' => 'NO_API_KEY'
        ];
    }

    if (!extension_loaded('curl')) {
        return [
            'exito' => false,
            'error' => 'La extensión PHP cURL no está disponible en este servidor.',
            'codigo' => 'NO_CURL'
        ];
    }

    $modelo = $opciones['modelo'] ?? $config['modelo'];
    $temperatura = (float) ($opciones['temperatura'] ?? 0.3);
    $maxTokens = (int) ($opciones['max_tokens'] ?? 1200);

    $payload = [
        'model' => $modelo,
        'messages' => $mensajes,
        'temperature' => $temperatura,
        'max_tokens' => $maxTokens,
        'top_p' => 0.9,
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init(GROQ_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $respuestaRaw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    // Si falló por verificación SSL local (común en XAMPP local sin cacert actualizado)
    if ($respuestaRaw === false && strpos($curlError, 'SSL') !== false) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $respuestaRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
    }

    curl_close($ch);

    if ($respuestaRaw === false) {
        return [
            'exito' => false,
            'error' => 'Error de conexión con Groq: ' . ($curlError ?: 'Sin respuesta del servidor'),
            'codigo' => 'CURL_ERROR'
        ];
    }

    $data = json_decode($respuestaRaw, true);

    if ($httpCode !== 200) {
        $mensajeError = $data['error']['message'] ?? "Error HTTP {$httpCode} desde la API de Groq";
        return [
            'exito' => false,
            'error' => $mensajeError,
            'codigo' => 'HTTP_' . $httpCode
        ];
    }

    $textoRespuesta = $data['choices'][0]['message']['content'] ?? '';
    if (trim($textoRespuesta) === '') {
        return [
            'exito' => false,
            'error' => 'Groq respondió pero no devolvió contenido de texto.',
            'codigo' => 'EMPTY_RESPONSE'
        ];
    }

    return [
        'exito' => true,
        'respuesta' => trim($textoRespuesta),
        'modelo' => $data['model'] ?? $modelo,
        'tokens' => $data['usage'] ?? [],
        'proveedor' => 'groq'
    ];
}

/**
 * Genera el Prompt de Sistema enriquecido con el contexto vivo de GROUP_CEA
 */
function construirPromptSistemaInstitucional(array $contextoVivo): string
{
    $usuario = $contextoVivo['usuario'] ?? 'Usuario';
    $rol = $contextoVivo['rol'] ?? 'Personal';
    $modulo = $contextoVivo['modulo'] ?? 'general';
    $fecha = $contextoVivo['fecha_actual'] ?? date('d/m/Y');

    // Contexto de datos serializados
    $datosJson = json_encode($contextoVivo['metricas'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return <<<PROMPT
Eres el Copiloto Inteligente de GROUP_CEA (Instituto Jurídico, Académico & Empresarial).
Tu objetivo es asistir de manera profesional, analítica, ejecutiva y precisa a directores, coordinadores académicos, asesores de tesis, abogados y ejecutivos comerciales.

DATOS DE LA SESIÓN:
- Usuario activo: {$usuario}
- Rol en el sistema: {$rol}
- Módulo actual: {$modulo}
- Fecha del sistema: {$fecha}

REGLAS DE IDENTIDAD Y COMPORTAMIENTO:
1. Tu nombre es "Copiloto CEA". Eres un asistente integrado en el sistema SaaS de GROUP_CEA.
2. Te especializas en las 3 áreas de GROUP_CEA:
   a) Académica & Tesis: Avance de tesistas, asignación a asesores, elección de temas, Cap. 1 y 2, revisión de Turnitin (antiplagio), simulación de sustentaciones y aprobación de tesis de grado/posgrado.
   b) Gestión Jurídica & Legal: Casos procesales, contratos de prestación de servicios, convenios institucionales, revisión con clientes, firma digital y archivo legal.
   c) Comercial & Ventas: Pipeline de captación de tesistas, leads interesados, cotizaciones, matrículas ganadas y seguimiento por WhatsApp.
   d) Gestión Financiera y Operativa: Recibos, estados de cuenta, control de cuotas y expedientes.
3. Responde siempre en español formal y ejecutivo, estructurado con viñetas, negritas y datos directos.
4. BASA TUS RESPUESTAS EN LOS DATOS REALES DEL SISTEMA PROPORCIONADOS A CONTINUACIÓN. Si te preguntan por métricas, alumnos, contratos o ventas, cita las cifras y nombres que aparecen en estos datos.
5. Sé proactivo: si identificas cuellos de botella (por ejemplo alumnos en fase de Turnitin pendientes de revisión, o contratos esperando visto bueno del cliente), proporciona recomendaciones y pasos sugeridos claros.

MÉTRICAS Y DATOS VIVOS DE LA BASE DE DATOS DE GROUP_CEA:
{$datosJson}
PROMPT;
}
