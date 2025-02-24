<?php
class SaleUtils
{
    public function isOverdue($date, $credit)
    {
        // Sumar $credit días a la fecha original
        $futureDate = strtotime($date . ' + ' . $credit . ' days');

        // Obtener la fecha actual
        $now = strtotime('now');

        // Calcular la diferencia entre las fechas
        $diff = $futureDate - $now;

        // Extract seconds, minutes, hours, days, and months from $diff
        $seconds = $diff % 60;
        $minutes = floor(($diff % 3600) / 60);
        $hours = floor(($diff % 86400) / 3600);
        $days = floor(abs($diff) / 86400);
        $months = floor($days / 30);

        $isOverdue = ($futureDate <= $now) ? true : false;

        $result = array(
            'isOverdue' => $isOverdue,
            'remaining' => array(
                'seconds' => $seconds,
                'minutes' => $minutes,
                'hours' => $hours,
                'days' => $days,
                'months' => $months
            )
        );
        /*foreach ($result['remaining'] as $value) {
            $value = abs($value);
        }*/
        return $result;
    }

    public function getRemainingTimeString($jsonData)
    {
        $remainingTime = '';

        if ($jsonData['remaining']['months'] > 0) {
            $remainingTime .= $jsonData['remaining']['months'] . ' Mes';
            if ($jsonData['remaining']['months'] > 1) {
                $remainingTime .= 'es';
            }
        } elseif ($jsonData['remaining']['days'] > 0) {
            $remainingTime .= $jsonData['remaining']['days'] . ' Día';
            if ($jsonData['remaining']['days'] > 1) {
                $remainingTime .= 's';
            }
        } elseif ($jsonData['remaining']['hours'] > 0) {
            $remainingTime .= $jsonData['remaining']['hours'] . ' Hora';
            if ($jsonData['remaining']['hours'] > 1) {
                $remainingTime .= 's';
            }
        } elseif ($jsonData['remaining']['minutes'] > 0) {
            $remainingTime .= $jsonData['remaining']['minutes'] . ' Minuto';
            if ($jsonData['remaining']['minutes'] > 1) {
                $remainingTime .= 's';
            }
        } elseif ($jsonData['remaining']['seconds'] > 0) {
            $remainingTime .= 'Justo Ahora';
        }

        return $remainingTime;
    }
}
