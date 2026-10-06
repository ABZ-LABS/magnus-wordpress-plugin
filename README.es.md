# Magnus Chat para WordPress

[English](README.md) · **Español**

Un plugin de WordPress que pone un agente de [Magnus](https://iamagnus.com) en un
sitio: una ventana de chat que responde a los visitantes. El dueño del sitio pega
una System API key; la key queda en el servidor y los visitantes nunca la ven.

```
navegador del visitante ──POST /wp-json/iamagnus-chat/v1/message──▶ WordPress ──POST /v1/chat/completions──▶ Magnus
                        ◀─────────────── { reply } ───────────────             (agrega la key)
```

- **Botón en una esquina o dentro de una página.** Un botón en todas las páginas,
  o el shortcode `[magnus_chat]` donde tenga que ir el chat. Donde está el
  shortcode no se muestra el botón de la esquina.
- **Una conversación por navegador.** El navegador guarda un id al azar; el plugin
  le manda a Magnus un hash con clave de ese id como `user`, que es como Magnus
  hila una conversación (30 minutos de inactividad). «Nueva conversación» empieza
  de cero.
- **Límites antes de llegar a Magnus.** Hasta 2.000 caracteres; 8 mensajes por
  minuto y 60 por hora por visitante (un visitante IPv6 es su /64), y 100 por hora
  para todo el sitio, por debajo de los 120 de la key. Sólo las páginas del propio
  sitio pueden llamar a la ruta. Se rechazan las entradas reservadas de Magnus
  (`/bot`, `/behavior`, `### Task:`, un `reset` solo) aunque vengan detrás de
  espacios Unicode. Cada turno lleva un `Idempotency-Key`, y «Reintentar» reenvía
  el mismo turno, así Magnus lo repite en vez de correrlo dos veces.
- **La key va sólo adonde se guardó.** No se siguen redirecciones (se llevarían
  la key), se rechazan direcciones internas, y cambiar la dirección de Magnus
  borra la key guardada salvo que venga una nueva con el cambio.
- **Cada falla se le cuenta a quien corresponde.** El visitante lee «No puedo
  responder en este momento»; el dueño ve la causa en Ajustes → Magnus Chat y puede
  probar la conexión ahí.
- **Inglés y español** (español neutro para todas las variantes del idioma). Los
  textos de la ventana se cambian en los ajustes.

Requiere WordPress 6.2+ y PHP 7.4+. Sin dependencias: llama a Magnus con la API
HTTP de WordPress, que respeta el proxy y los certificados del hosting.

## Instalar

1. Arma el zip (`bin/build-zip.sh`) o bájalo de un release, y súbelo en Plugins →
   Añadir nuevo → Subir plugin.
2. En el panel de Magnus, crea una System API key para el agente que debe
   responder en el sitio.
3. Ajustes → Magnus Chat: pega la key, guarda y aprieta **Probar la conexión**.

## Probarlo en tu máquina

[WordPress Playground](https://wordpress.github.io/wordpress-playground/) corre
WordPress dentro de Node, sin PHP ni Docker en la máquina:

```bash
npx @wp-playground/cli@3 start --path=.
```

Abre un WordPress con este plugin activo y con tu sesión de administrador.

## Desarrollo

```bash
bin/test.sh          # la ventana en jsdom, y el plugin en WordPress con PHP 8.3 y 7.4
bin/test.sh live     # contra el Magnus real con una key inventada: el camino y los rechazos
MAGNUS_API_KEY=... bin/test.sh live   # un turno real (gasta tokens)
bin/build-zip.sh     # dist/iamagnus-chat-<versión>.zip, y las pruebas contra esa copia
bin/i18n.py          # regenera el .pot, revisa el .po en español y compila los .mo
```

Las pruebas de PHP corren dentro de un WordPress real (Playground) y responden
cada pedido a Magnus desde un filtro `pre_http_request`, que además registra lo
que mandó el plugin. Cualquier warning de PHP que salga del plugin hace fallar la
corrida.

El formato que usa el plugin es el contrato `/v1` de Magnus, el mismo que siguen
los [SDKs](https://github.com/ABZ-LABS/magnus-python-sdk/blob/main/CONTRACT.es.md).

## Publicar una versión

1. Pon la versión en tres lugares: la cabecera `Version:` y
   `IAMAGNUS_CHAT_VERSION` en `iamagnus-chat.php`, y `Stable tag:` en
   `readme.txt`. `bin/build-zip.sh` no arma el zip si no coinciden.
2. `bin/test.sh && bin/build-zip.sh`.
3. Pon el tag al commit (`v0.1.0`) y adjunta el zip al release.

Para el directorio de WordPress.org, agrega a `readme.txt` una línea
`Contributors:` con los usuarios de wordpress.org antes de enviarlo.

## Licencia

GPL-2.0-or-later, como los plugins de WordPress. Ver [LICENSE](LICENSE).
