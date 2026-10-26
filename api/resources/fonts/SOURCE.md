# Noto Sans SC export fonts

Source: [Google Fonts Noto Sans SC](https://github.com/google/fonts/tree/a85815a42757630ce188fdad368c2dfc444d4773/ofl/notosanssc), commit `a85815a42757630ce188fdad368c2dfc444d4773`, `NotoSansSC[wght].ttf`. The accompanying `NotoSansSC-OFL.txt` permits redistribution and embedding under SIL OFL 1.1. No download occurs during export.

Static Regular (400) and Bold (700) faces were instantiated with fontTools 4.67.0, retaining all 30,890 encoded characters (31,036 glyphs), embedding permission `fsType=0`. SHA-256:

- Regular: `5f350ebec58a04afe5db2b910c66034e72be7678219db12e30d3f074f0d02771`
- Bold: `826208feb28f556c2116d704a9bf6ed4665c411e5f6e00acd9ad29052d8173b2`

DOCX embeds both complete faces using ECMA-376 font obfuscation and includes the license. They remain available for editing; CJK runs use Noto Sans SC, while Latin runs preserve Calibri.

Browser PDF uses pdfmake/PDFKit with these complete TTF faces and DejaVu Sans regular/bold. Only required fonts are fetched from the application's hashed public assets when export starts. Noto SC is loaded only for CJK content. Public TTF responses may be stored in Cache API; private project JSON never is. PDFKit embeds actual glyph subsets in the generated PDF. No fontTools, font metrics generator or Node service runs on the backend. Dompdf-specific shards and UFM metrics were removed in Milestone 6.2; their history remains in commit cd008cd.

DejaVu Sans regular/bold were copied unchanged from the previously installed Dompdf 3.1.6 distribution before removing the dependency. Their Bitstream Vera/DejaVu redistribution license is retained as DejaVu-LICENSE.txt. These are frontend build inputs; the PDF Worker loads the emitted static assets.
