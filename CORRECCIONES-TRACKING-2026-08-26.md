# Correcciones de tracking aplicadas

## Alcance

Se aplicaron en `NUVANX-Medicina-Estetica-Laser/nuvanx-siteground` las correcciones que pueden ejecutarse de forma segura desde el repositorio. Las operaciones administrativas en Google Ads, Google Tag Manager, HubSpot, Search Console y Google Business Profile requieren acceso a sus respectivas interfaces y no se han simulado ni dado por realizadas.

## Cambios realizados

| Área | Cambio | Resultado |
| --- | --- | --- |
| Conversión web | Se eliminó la emisión legacy `nvx_valoracion_success` del relay de conversiones. | El formulario ya no expone el evento específico que podía activar un tag directo de Ads. |
| Arquitectura canónica | Se conservó `generate_lead` como evento GA4 del envío exitoso de HubSpot. | Se mantiene el flujo `HubSpot → GA4 generate_lead → Ads 908 import`. |
| Teléfono / WhatsApp | Se conservó el `send_to` de 820 para clics de teléfono/WhatsApp. | Se respeta la separación indicada: no se mezcló esa medición con la limpieza de formularios. |
| Publisher GTM | Se eliminó `scripts/seo/setup-gtm-conversion-trigger.js`. | El repositorio ya no contiene el publisher que podía crear un segundo owner directo de Google Ads para formularios. |
| Documentación | Se actualizó `scripts/seo/README.md`. | Se documenta el ownership canónico y se indica que los cambios live de GTM deben verificarse administrativamente. |
| Regresión | Se añadió `scripts/lint/test-conversion-ownership-contract.mjs`. | Se bloquea la reintroducción del publisher, del evento legacy y del tag directo 908; se protege la medición 820 de teléfono/WhatsApp. |

## Validación

Las siguientes comprobaciones finalizaron correctamente:

- Sintaxis JavaScript del relay modificado.
- Sintaxis del nuevo contrato de regresión.
- Contrato de ownership de conversiones.
- Contrato de atribución HubSpot/GA4 existente.
- `git diff --check`.

El contrato completo de release no pudo finalizar porque el entorno de ejecución no incluye `php`; falló en la comprobación existente de retirada del owner Meta browser, no en los cambios de tracking aplicados.

## Acciones administrativas pendientes

Antes del QA único todavía deben completarse en las interfaces correspondientes las comprobaciones indicadas en el backlog: Primary/Secondary de las cuatro acciones de formulario en Ads 908; Primary/Secondary de las cuatro acciones relevantes en Ads 820; eliminación o pausa y publicación de tags directos en GTM; revisión de HubSpot Ads > Events sin desconectar Facebook Lead Ads; y el submit controlado que confirme exactamente un `generate_lead` y una importación en la acción 7733772435.

Después del saneamiento de conversiones deben abordarse el geo targeting `PRESENCE` de la campaña 24167785177, la decisión de consolidación de la PMax activa de 820, los envíos incorrectos de GSC y la gobernanza de categorías/servicios de GBP. La propiedad GA4 secundaria 541588939 no se ha eliminado porque el archivo de correcciones indica que no existe evidencia suficiente para hacerlo.
