# Noto Sans SC export fonts

Source: [Google Fonts Noto Sans SC](https://github.com/google/fonts/tree/a85815a42757630ce188fdad368c2dfc444d4773/ofl/notosanssc), commit `a85815a42757630ce188fdad368c2dfc444d4773`, `NotoSansSC[wght].ttf`. The accompanying `NotoSansSC-OFL.txt` permits redistribution and embedding under SIL OFL 1.1. No download occurs during export.

Static Regular (400) and Bold (700) faces were instantiated with fontTools 4.67.0, retaining all 30,890 encoded characters (31,036 glyphs), embedding permission `fsType=0`. SHA-256:

- Regular: `5f350ebec58a04afe5db2b910c66034e72be7678219db12e30d3f074f0d02771`
- Bold: `826208feb28f556c2116d704a9bf6ed4665c411e5f6e00acd9ad29052d8173b2`

DOCX embeds both complete faces using ECMA-376 font obfuscation and includes the license. They remain available for editing; CJK runs use Noto Sans SC, while Latin runs preserve Calibri.

Dompdf must parse fonts within the existing PHP 128 MiB limit. `NotoSansSC-Pdf.json` partitions the sorted Unicode cmap into groups of 1,024. Each Regular/Bold PDF face retains its group plus U+0020, without hinting or layout features; `.ufm` metrics were generated with the installed php-font-lib. PDF registers only ranges used in the document, retaining DejaVu Sans for Latin, Cyrillic and Turkish. Dompdf embeds subsets of these real TrueType glyphs. All source CJK ranges remain available, including user text absent from dictionaries. Font files are immutable; each PDF request uses its own temporary cache.

To reproduce PDF faces, use fontTools `subset.Subsetter` with `layout_features=[]`, `hinting=False`, `recalc_timestamp=False`, and `populate(unicodes=[32, *group])` on each static face. Set name IDs 1/4/6 to `ProjectBrief CJK {id}`, `ProjectBrief CJK {id} {weight}`, `NotoSansSC-Pdf{id}-{weight}`. Generate Adobe metrics with `FontLib\Font::load($path)->saveAdobeFontMetrics(...)` after parsing. FontTools is a preparation tool only and is not a runtime dependency.
