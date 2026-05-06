<?php
/**
 * SunatBuilder — Construye el payload JSON que la API Laravel espera.
 *
 * Convierte los datos del dominio DentalSys (pago, paciente, pago_detalles)
 * al formato que pide GenerarComprobanteRequest. No habla con red ni BD.
 */
class SunatBuilder
{
    /**
     * @param array $pago     Fila de `pagos` con tipo_comprobante, serie, numero, fecha.
     * @param array $paciente Fila de `pacientes` (dni, ruc, nombres, apellidos, direccion).
     * @param array $items    Filas de `pago_detalles` (concepto, cantidad, precio).
     */
    public static function buildComprobante(array $pago, array $paciente, array $items): array
    {
        $tipo = $pago['tipo_comprobante']; // 'factura' | 'boleta'
        $aplica_igv = !isset($pago['aplica_igv']) || $pago['aplica_igv'];

        return [
            'endpoint'      => SUNAT_ENDPOINT,
            'documento'     => $tipo,
            'empresa'       => self::empresa(),
            'cliente'       => self::cliente($paciente, $tipo),
            'serie'         => $pago['serie'],
            'numero'        => (string) $pago['numero'],
            'fecha_emision' => $pago['fecha'] ?? date('Y-m-d H:i:s'),
            'moneda'        => 'PEN',
            'forma_pago'    => 'contado',
            'detalles'      => self::detalles($items, $aplica_igv),
            'aplica_igv'    => $aplica_igv,
        ];
    }

    private static function empresa(): array
    {
        return [
            'ruc'             => SUNAT_RUC,
            'usuario'         => SUNAT_USUARIO_SOL,
            'clave'           => SUNAT_CLAVE_SOL,
            'razon_social'    => SUNAT_RAZON_SOCIAL,
            'nombreComercial' => SUNAT_NOMBRE_COMERCIAL,
            'direccion'       => SUNAT_DIRECCION,
            'ubigueo'         => SUNAT_UBIGEO,
            'distrito'        => SUNAT_DISTRITO,
            'provincia'       => SUNAT_PROVINCIA,
            'departamento'    => SUNAT_DEPARTAMENTO,
        ];
    }

    /**
     * Factura → requiere RUC. Boleta → DNI o "varios".
     * El nombre completo se arma con nombres + apellido_paterno + apellido_materno.
     */
    private static function cliente(array $p, string $tipo): array
    {
        $ruc = trim($p['ruc'] ?? '');
        $dni = trim($p['dni'] ?? '');
        $nom = trim(($p['nombres'] ?? '') . ' ' . ($p['apellido_paterno'] ?? '') . ' ' . ($p['apellido_materno'] ?? ''));
        $nom = preg_replace('/\s+/', ' ', $nom) ?: 'CLIENTE';
        $dir = trim($p['direccion'] ?? '-') ?: '-';

        if ($tipo === 'factura') {
            if ($ruc === '' || strlen($ruc) !== 11) {
                throw new RuntimeException("El paciente '$nom' no tiene RUC válido (11 dígitos). Las facturas requieren RUC.");
            }
            return ['tipo_doc' => '6', 'num_doc' => $ruc, 'rzn_social' => $nom, 'direccion' => $dir];
        }

        // Boleta
        if ($dni !== '' && strlen($dni) === 8) {
            return ['tipo_doc' => '1', 'num_doc' => $dni, 'rzn_social' => $nom, 'direccion' => $dir];
        }
        return ['tipo_doc' => '0', 'num_doc' => '00000000', 'rzn_social' => $nom !== '' ? $nom : 'CLIENTE VARIOS', 'direccion' => $dir];
    }

    /**
     * `pago_detalles.precio` se asume CON IGV incluido cuando aplica_igv=true
     * (el servicio Greenter divide entre 1.18 internamente).
     * Cuando aplica_igv=false, se marca como inafecto.
     */
    private static function detalles(array $items, bool $aplica_igv = true): array
    {
        $out = [];
        foreach ($items as $i => $it) {
            $out[] = [
                'cod_producto' => (string) ($it['id'] ?? ($i + 1)),
                'unidad'       => 'NIU',
                'descripcion'  => $it['concepto'] ?? 'Servicio dental',
                'cantidad'     => (float) ($it['cantidad'] ?? 1),
                'precio'       => (float) ($it['precio'] ?? 0),
                'tipo_igv'     => $aplica_igv ? 'gravado' : 'inafecto',
            ];
        }
        return $out;
    }
}
