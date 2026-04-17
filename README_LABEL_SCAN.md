# `POST /label/scan` — Scan de etiquetas con broadcast Pusher

El móvil sube la foto de una etiqueta. El backend la analiza con IA de visión,
busca el ítem en la BD y **dispara en tiempo real** un evento Pusher para que
la web de verificación (proyecto `new`) lo reciba sin polling.

---

## 1. Request

```http
POST /label/scan?session=TESTSESSION123
Authorization: Bearer <API_KEY_ADMIN>
Content-Type: multipart/form-data

Campos:
  image    (file, JPEG/PNG, <= 10 MB, requerido)
  session  (string A-Z0-9, 8–32 chars; aceptado en query string o form)
```

Ejemplo curl:

```bash
curl -X POST "http://localhost/newApi/label/scan?session=TESTSESSION123" \
  -H "Authorization: Bearer NS20gEo80zV6F3WoxFOR5UKgztqilJ63" \
  -F "image=@/ruta/a/etiqueta.jpg"
```

---

## 2. Response (200 OK)

```json
{
  "ok": true,
  "code": "GS-145",
  "cb": "CB-167909",
  "qty": 25,
  "purchase_number": 6,
  "item": {
    "uuid": "29aa0bab-492c-48a1-b41d-277c3fda7069",
    "id": "GS-145",
    "description": "Clip De Tapiceria Toyota"
  },
  "qr": "<contenido crudo del QR si se pudo decodificar>"
}
```

Este mismo payload (sin mostrar `qr` si no vino) se envía también por Pusher.

### Errores

| Código | Cuerpo                                   | Causa                               |
|--------|------------------------------------------|-------------------------------------|
| 400    | `{"ok":false,"error":"image_missing"}`   | sin campo `image` / mime inválido   |
| 400    | `{"ok":false,"error":"session_invalid"}` | `session` vacío o formato inválido  |
| 401    | `{"ok":false,"error":"unauthorized"}`    | token Bearer inválido o ausente     |
| 413    | `{"ok":false,"error":"image_too_large"}` | imagen > 10 MB                      |
| 422    | `{"ok":false,"error":"unreadable"}`      | la IA no pudo leer la etiqueta      |
| 500    | `{"ok":false,"error":"internal"}`        | fallo inesperado                    |

**Ningún error dispara Pusher** — solo el caso `ok:true`.

---

## 3. Evento Pusher

| Campo    | Valor                                  |
|----------|----------------------------------------|
| App ID   | `2142801`                              |
| Cluster  | `us2`                                  |
| Canal    | `session-<SESSION>` (ej: `session-TESTSESSION123`) |
| Evento   | `scan_result`                          |
| Payload  | mismo JSON que la response HTTP        |

### Suscripción en la web (proyecto `new`)

```js
import Pusher from 'pusher-js';

const pusher = new Pusher('2898b37648c1dad5dc78', { cluster: 'us2' });
const channel = pusher.subscribe(`session-${sessionId}`);

channel.bind('scan_result', (data) => {
  console.log('Scan recibido:', data);
});
```

---

## 4. Verificación manual

1. Corre el curl de arriba con una `?session=TESTSESSION123`.
2. Abre el [Pusher Debug Console](https://dashboard.pusher.com/apps/2142801/console)
   y filtra por canal `session-TESTSESSION123`. Debe aparecer el evento
   `scan_result` con el mismo JSON que el móvil recibió.
3. Si el evento llega al dashboard pero no a la web, el problema está en el
   cliente JS, no en el backend.

---

## 5. Configuración (`.env`)

```env
# Pusher Channels
PUSHER_APP_ID=2142801
PUSHER_KEY=2898b37648c1dad5dc78
PUSHER_SECRET=c85e23f2d07ced34deb4
PUSHER_CLUSTER=us2

# IA visión
GROQ_API_KEY=<tu key de Groq>
```

El `PUSHER_SECRET` **solo** vive en este backend — el móvil nunca lo ve.

---

## 6. Arquitectura

```
newApi/
├── controllers/LabelController.php   # endpoint scan()
├── services/PusherService.php        # singleton Pusher client
├── py/
│   ├── qr_decode.py                  # decodifica QR con OpenCV+pyzbar
│   └── md5_reverse.py                # revierte CB-XXXXXX → purchase_number
└── .env
```

- **IA visión**: Groq `llama-4-scout-17b-16e-instruct` (timeout 25 s).
- **QR decode**: best-effort con `py/qr_decode.py`; si falla no rompe el scan.
- **Purchase number**: se prefiere recuperar desde el hash MD5 de `CB-XXXXXX`;
  si falla se usa el valor de la IA como fallback.

---

## 7. Cosas que **no** hace este endpoint

- **No** guarda la imagen más allá del request (se borra con `unlink()`).
- **No** expone `PUSHER_SECRET` en ningún response ni log.
- **No** usa WebSockets propios ni Ratchet — Pusher hace el fan-out.
- **No** falla el request si `$pusher->trigger()` lanza excepción; se loguea
  y se sigue respondiendo al móvil (la web puede resincronizar luego).
