# Matriz de alineación visual: Figma Make vs. tema NUVANX

Esta matriz traduce el diseño «Calibrar diseño web médico» a cambios concretos y acotados sobre el tema WordPress. La referencia no plantea un rediseño completo: conserva la tipografía Playfair Display + Manrope, el contenedor de 1240 px y el lenguaje editorial clínico. [Figma](https://www.figma.com/make/vbNrG575LMEfoCFMb64BWc/Calibrar-dise%C3%B1o-web-m%C3%A9dico)

| Área | Referencia Figma | Estado del tema | Acción de alineación |
| --- | --- | --- | --- |
| Ritmo vertical | 48 / 64 / 96 px; compacto 32 / 48 / 64 px; subbloques 24 / 32 / 48 px | Ya coincide en `nvx-base.css` | Consolidar estos tokens como contrato del sistema y eliminar los aliases fluidos contradictorios. |
| Gutters | 24 / 40 / 48 px por lado; el contenedor es el único propietario del margen horizontal | Los valores existen, pero están duplicados entre tokens y layout | Reunir el contrato en tokens semánticos y hacer que el shell lo consuma sin padding lateral adicional. |
| Cards editoriales | Retícula 3 / 2 / 1; líneas de 1 px; padding 20 / 24 / 32 px; esquinas rectas; sin sombras | Blog y foto auténtica ya usan parte del patrón; las cards genéricas usan padding fijo de 32 px y medios con hover de sombra | Sustituir el padding fijo por el token responsive, retirar sombras de hover en media editorial y reforzar bordes/líneas como principal separación. |
| Dirección cromática | El fondo neutro permanece; fotografía aporta el tono. No utilizar beige u oro como recurso decorativo. Iconos, numerales y texto de botones se apoyan en tinta. | El tema declara acentos oro, aunque el footer ya no depende de ellos | No ampliar el uso de oro. El ajuste se limita a componentes intervenidos, con tinta, neutros fríos y fotografía como foco visual. |
| Footer desktop | Grid de 12 columnas; marca 2, tratamientos 4 (en dos subcolumnas), clínicas 2, NUVANX 2; líneas de 1 px y jerarquía editorial | Distribución proporcional 25 / 33 / 22 / 20; la estructura de `details` no se abre de forma nativa en desktop | Implementar grid explícito de 12 columnas, resetear tamaños, separar grupos con reglas y abrir sus contenidos en desktop mediante script accesible. |
| Footer tablet | Marca en franja separada y tres columnas: tratamientos / clínicas / NUVANX | Coincide parcialmente en 2fr / 1fr / 1fr | Mantener la composición y ajustar espaciado, padding y títulos a los tokens nuevos. |
| Footer móvil | Acordeones nativos cerrados, separadores de 1 px y 12 px entre enlaces | Tiene `details`, pero no define una inicialización responsive consistente | Añadir una mejora progresiva: abierto a partir de 768 px y acordeones cerrados por debajo, conservando controles nativos y teclado. |

## Límites de esta iteración

La rama de alineación visual no modifica contenido clínico, URLs, tarifas, SEO, dependencias de WordPress ni archivos de configuración de SiteGround. Tampoco introduce imágenes generadas; la directriz de Figma se implementa mediante jerarquía, layout y el uso de las fotografías existentes.
