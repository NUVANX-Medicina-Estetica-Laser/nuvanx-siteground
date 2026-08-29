## Alcance

Describe el problema, la fuente de verdad propietaria y el cambio mínimo que lo resuelve.

## Verificación

- [ ] Verifiqué todos los consumidores/referencias afectados por este cambio.
- [ ] Ejecuté los tests estáticos/semánticos relevantes para los archivos modificados.
- [ ] No añadí workflows temporales/one-time, residuos generados ni documentación duplicada.
- [ ] No introduje valores hardcoded que ya tengan un owner machine-readable.

## Impacto runtime / release

- [ ] No cambia comportamiento runtime/release.
- [ ] Cambia runtime/release; después del merge, el nuevo SHA debe completar el Staging canónico antes de cualquier promoción a Production.

Si cambia deployment, migraciones, seguridad, analytics o ownership de una integración, documenta el rollback/fail-closed y la evidencia que demuestra el nuevo owner.

## Integración del tema

Si cambia `functions.php`, `require_once` o módulos del tema:

- [ ] El archivo/módulo existe en la ruta esperada.
- [ ] Se carga exactamente una vez desde el owner previsto.
- [ ] Pasan los checks de sintaxis PHP/JS aplicables.
- [ ] No se introdujeron hooks, renderers, conversion owners o metadata emitters duplicados.
