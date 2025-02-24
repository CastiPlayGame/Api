<?php

use LDAP\Result;

class Template
{
    private $template;
    private $data;
    private $options;

    public function __construct($templatePath, $options = array())
    {
        $this->template = file_get_contents($templatePath);
        $this->options = $options;
    }

    public function with($data)
    {
        $this->data = $data;
        return $this;
    }

    public function render()
    {
        $data = $this->data;

        // Reemplazar condicionales
        $this->template = preg_replace_callback('/@if\s*\(\s*([a-zA-Z0-9_]+)\s*\)(.*?)@endif/s', function ($match) use ($data) {
            $condition = isset($data[$match[1]]) && $data[$match[1]];
            if ($condition) {
                return $match[2]; // Si la condición es verdadera, retorna el contenido dentro del @if
            } else {
                return ''; // Si la condición es falsa, retorna una cadena vacía
            }
        }, $this->template);

        // Reemplazar bucles foreach
        $this->template = preg_replace_callback(
            '/@foreach\s*\((?:([a-zA-Z0-9_]+)\s+to\s+)?([a-zA-Z0-9_]+)\s+as\s+([a-zA-Z0-9_]+)\s*\)(.*?)@endforeach/s',
            function ($match) use ($data) {
                $counterName = isset($match[1]) ? $match[1] : null;
                $arrayName = isset($match[1]) ? $match[2] : $match[1];
                $itemName = isset($match[1]) ? $match[3] : $match[2];
                $content = $match[4];

                // Obtener el arreglo de datos
                $array = $data[$arrayName];

                // Inicializar el contenido reemplazado
                $replacedContent = '';

                // Recorrer el arreglo y reemplazar el contenido
                $counter = 1;
                foreach ($array as $item) {
                    $tempContent = $content;

                    // Agregar contador si está definido
                    if ($counterName) {
                        // Replace base counter variable
                        $tempContent = str_replace('{{ ' . $counterName . ' }}', $counter, $tempContent);

                        // Handle mathematical expressions
                        preg_match_all('/{{\s*(' . $counterName . ')\s*([\+\-\*\/]\s*\d+)\s*}}/', $tempContent, $expr_matches);

                        foreach ($expr_matches[0] as $index => $full_match) {
                            $operation = $expr_matches[2][$index];
                            $calculated = $counter . $operation;
                            $result = eval("return $calculated;");
                            $tempContent = str_replace($full_match, $result, $tempContent);
                        }
                    }

                    $pattern = '/{{\s*' . $itemName . '\.(.*?)\s*}}/';
                    preg_match_all($pattern, $content, $matches);
                    foreach ($matches[1] as $property) {
                        $value = $item;
                        $properties = explode('.', $property);
                        foreach ($properties as $prop) {
                            if (isset($value[$prop])) {
                                $value = $value[$prop];
                            } else {
                                $value = '';
                                break;
                            }
                        }
                        $tempContent = preg_replace('/{{\s*' . $itemName . '\.' . $property . '\s*}}/', $value, $tempContent);
                    }
                    $replacedContent .= $tempContent;
                    $counter++;
                }

                return $replacedContent;
            },
            $this->template
        );

        // Reemplazar variables
        $this->template = preg_replace_callback('/{{\s*(.*?)\s*}}/', function ($match) use ($data) {
            $variable = trim($match[1]); // Get the variable name without whitespace
            return isset($data[$variable]) ? $data[$variable] : ''; // Replace with the value from $data or an empty string if not found
        }, $this->template);

        // Ejecutar funciones
        $this->template = preg_replace_callback(
            '/(?:([^|]+?)\s*\|\s*)?&([a-zA-Z0-9_]+)\(([^)]*)\)/',
            function ($m) use ($data) {
                $funcName = $m[2];
                $paramsString = $m[3];

                // 1. Dividir parámetros respetando comillas
                $params = preg_split('/\s*,\s*(?=(?:[^"\']|"[^"]*"|\'[^\']*\')*$)/', $paramsString);

                foreach ($params as &$param) {
                    $param = trim($param);

                    // 2. Eliminar comillas circundantes (ej: "{{var}}" → {{var}})
                    if (preg_match('/^(["\'])(.*)\1$/', $param, $quotes)) {
                        $param = $quotes[2];
                    }

                    // 3. Reemplazar variables dentro del parámetro
                    $param = preg_replace_callback('/{{\s*(.*?)\s*}}/', function ($pm) use ($data) {
                        return $data[trim($pm[1])] ?? '';
                    }, $param);
                }

                // Ejecutar la función
                return function_exists($funcName)
                    ? call_user_func_array($funcName, $params)
                    : "FUNCIÓN $funcName NO ENCONTRADA";
            },
            $this->template
        );

        // Incluir CSS
        if (isset($this->options['include_css']) && $this->options['include_css']) {
            $this->template = $this->loadCss($this->template);
        }
        return $this->template;
    }

    private function loadCss($template)
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress HTML parsing errors
        $dom->loadHTML($template);
        libxml_clear_errors();

        $links = $dom->getElementsByTagName('link');
        $linksToRemove = [];
        $stylesToAdd = [];

        foreach ($links as $link) {
            $rel = $link->getAttribute('rel');
            $href = $link->getAttribute('href');

            if ($rel === 'stylesheet' && !empty($href)) {
                $css = file_get_contents($href);
                if ($css === false) {
                    continue;
                }

                $style = $dom->createElement('style', $css);
                $style->setAttribute('type', 'text/css');

                $stylesToAdd[] = $style;
                $linksToRemove[] = $link;
            }
        }

        // Replace links with styles outside the loop to avoid modifying the collection while iterating
        foreach ($linksToRemove as $link) {
            $link->parentNode->removeChild($link);
        }

        foreach ($stylesToAdd as $style) {
            $dom->appendChild($style);
        }

        return $dom->saveHTML();
    }
}
