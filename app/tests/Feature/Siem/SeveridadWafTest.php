<?php

namespace Tests\Feature\Siem;

use App\Models\EventoSeguridad;
use App\Services\Siem\Normalizador;
use Tests\TestCase;

/**
 * Calibracion de la severidad de los eventos del cortafuegos.
 *
 * En modo de puntuacion de anomalia, la etiqueta de severidad de una regla del Core Rule
 * Set es un peso, no un veredicto: casi todas sus reglas de ataque son CRITICAL porque
 * suman cinco puntos. El veredicto lo da el WAF al bloquear o no.
 *
 * Tomar la etiqueta como severidad convertia cada coincidencia de paranoia 2 sin bloqueo en
 * un evento critico, y cada uno abria su propia alerta: cientos al dia por el escaneo
 * automatizado de Internet. Estas pruebas fijan que eso no vuelva a pasar, y a la vez que
 * lo que si es grave siga saliendo como grave.
 */
class SeveridadWafTest extends TestCase
{
    private function evento(bool $bloqueado, array $mensajes): array
    {
        $resultado = app(Normalizador::class)->desdeAuditoriaWaf([
            'transaction' => [
                'client_ip' => '198.51.100.23',
                'time_stamp' => 'Wed Sep 23 18:00:00 2026',
                'unique_id' => 'prueba-'.md5(serialize([$bloqueado, $mensajes])),
                'is_interrupted' => $bloqueado,
                'request' => ['method' => 'GET', 'uri' => '/tienda?q=prueba', 'headers' => []],
                'response' => ['http_code' => $bloqueado ? 403 : 200],
                'messages' => $mensajes,
            ],
        ]);

        $this->assertIsArray($resultado);

        return $resultado;
    }

    private function mensaje(string $regla, int $severidad, string $texto = 'SQL Injection Attack Detected'): array
    {
        return [
            'message' => $texto,
            'details' => ['ruleId' => $regla, 'severity' => (string) $severidad, 'tags' => [], 'data' => ''],
        ];
    }

    public function test_una_coincidencia_critical_sin_bloqueo_no_es_un_evento_critico(): void
    {
        // Una sola regla etiquetada CRITICAL que el WAF registro y dejo pasar: la huella de un
        // sondeo en paranoia 2. Suma cinco puntos, que es lo que pesa, no lo que significa.
        $evento = $this->evento(false, [$this->mensaje('942100', 2)]);

        $this->assertFalse($evento['fue_bloqueado']);
        $this->assertNotSame(EventoSeguridad::SEVERIDAD_CRITICA, $evento['severidad']);
        $this->assertSame(EventoSeguridad::SEVERIDAD_MEDIA, $evento['severidad']);
    }

    public function test_la_misma_coincidencia_bloqueada_si_es_critica(): void
    {
        // Con bloqueo el WAF confirmo el ataque, y la etiqueta de la regla vuelve a contar.
        $evento = $this->evento(true, [$this->mensaje('942100', 2)]);

        $this->assertTrue($evento['fue_bloqueado']);
        $this->assertSame(EventoSeguridad::SEVERIDAD_CRITICA, $evento['severidad']);
    }

    public function test_una_carga_con_puntuacion_alta_es_critica_aunque_pase(): void
    {
        // Lo que si es grave no se esconde: una carga de treinta puntos que atraveso el WAF
        // —porque estaba en solo deteccion, por ejemplo— sigue siendo critica por la
        // puntuacion, no por la etiqueta.
        $evento = $this->evento(false, [
            $this->mensaje('942100', 2),
            $this->mensaje('949110', 2, 'Inbound Anomaly Score Exceeded (Total Score: 30)'),
        ]);

        $this->assertFalse($evento['fue_bloqueado']);
        $this->assertSame(EventoSeguridad::SEVERIDAD_CRITICA, $evento['severidad']);
    }

    public function test_sin_ninguna_regla_una_transaccion_que_pasa_es_informativa(): void
    {
        $evento = $this->evento(false, []);

        $this->assertSame(EventoSeguridad::SEVERIDAD_INFORMATIVA, $evento['severidad']);
    }
}
