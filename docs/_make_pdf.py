# -*- coding: utf-8 -*-
"""Rendu Markdown -> PDF (reportlab) pour le guide de déploiement MadMen.
Gère : titres #/##/###, paragraphes, **gras**, `code` inline, blocs ``` ```,
tables |...|, listes - / 1., citations >, règles ---."""
import re, html, sys
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Preformatted,
                                Table, TableStyle, HRFlowable, KeepTogether)

SRC = sys.argv[1]
OUT = sys.argv[2]

TEAL = colors.HexColor('#0d8b80')
DARK = colors.HexColor('#12262b')
CODEBG = colors.HexColor('#f4f5f6')
CODEBORDER = colors.HexColor('#d9dee0')

ss = getSampleStyleSheet()
styles = {
    'title': ParagraphStyle('t', parent=ss['Title'], fontSize=20, textColor=TEAL, spaceAfter=10, leading=24),
    'h1':    ParagraphStyle('h1', parent=ss['Heading1'], fontSize=14, textColor=TEAL, spaceBefore=14, spaceAfter=6, leading=17),
    'h2':    ParagraphStyle('h2', parent=ss['Heading2'], fontSize=11.5, textColor=DARK, spaceBefore=10, spaceAfter=4, leading=14),
    'body':  ParagraphStyle('b', parent=ss['BodyText'], fontSize=9.5, leading=13.5, spaceAfter=4, alignment=TA_LEFT),
    'quote': ParagraphStyle('q', parent=ss['BodyText'], fontSize=9, leading=12.5, leftIndent=10, textColor=colors.HexColor('#5d6b63'), borderPadding=2),
    'li':    ParagraphStyle('li', parent=ss['BodyText'], fontSize=9.5, leading=13, leftIndent=14, bulletIndent=4, spaceAfter=2),
    'code':  ParagraphStyle('c', fontName='Courier', fontSize=7.4, leading=9.4, textColor=DARK),
    'thead': ParagraphStyle('th', parent=ss['BodyText'], fontSize=8.5, leading=11, textColor=colors.white, fontName='Helvetica-Bold'),
    'tcell': ParagraphStyle('tc', parent=ss['BodyText'], fontSize=8.3, leading=11),
}

def inline(t):
    t = html.escape(t, quote=False)
    t = re.sub(r'\*\*(.+?)\*\*', r'<b>\1</b>', t)
    t = re.sub(r'`([^`]+?)`', r'<font face="Courier" size="8.3" color="#b5651a">\1</font>', t)
    return t

lines = open(SRC, encoding='utf-8').read().split('\n')
flow = []
i = 0
USABLE = A4[0] - 3.0*cm  # marges 1.5cm de chaque côté

def add_table(rows):
    # rows: liste de listes (cellules texte). 1re = en-tête, 2e = séparateur (ignorée).
    header = [Paragraph(inline(c), styles['thead']) for c in rows[0]]
    body = [[Paragraph(inline(c), styles['tcell']) for c in r] for r in rows[2:]]
    data = [header] + body
    ncol = len(rows[0])
    w = USABLE / ncol
    t = Table(data, colWidths=[w]*ncol, repeatRows=1)
    st = [('BACKGROUND',(0,0),(-1,0), TEAL),
          ('GRID',(0,0),(-1,-1),0.4, colors.HexColor('#cfd8d6')),
          ('VALIGN',(0,0),(-1,-1),'TOP'),
          ('LEFTPADDING',(0,0),(-1,-1),5),('RIGHTPADDING',(0,0),(-1,-1),5),
          ('TOPPADDING',(0,0),(-1,-1),3),('BOTTOMPADDING',(0,0),(-1,-1),3)]
    for r in range(1, len(data)):
        if r % 2 == 0:
            st.append(('BACKGROUND',(0,r),(-1,r), colors.HexColor('#f4f7f6')))
    t.setStyle(TableStyle(st))
    flow.append(Spacer(1,4)); flow.append(t); flow.append(Spacer(1,6))

while i < len(lines):
    line = lines[i]
    s = line.strip()
    # bloc de code
    if s.startswith('```'):
        i += 1; buf = []
        while i < len(lines) and not lines[i].strip().startswith('```'):
            buf.append(html.escape(lines[i], quote=False)); i += 1
        i += 1
        code = Preformatted('\n'.join(buf) if buf else ' ', styles['code'])
        box = Table([[code]], colWidths=[USABLE])
        box.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,-1),CODEBG),
                                 ('BOX',(0,0),(-1,-1),0.5,CODEBORDER),
                                 ('LEFTPADDING',(0,0),(-1,-1),6),('RIGHTPADDING',(0,0),(-1,-1),6),
                                 ('TOPPADDING',(0,0),(-1,-1),4),('BOTTOMPADDING',(0,0),(-1,-1),4)]))
        flow.append(box); flow.append(Spacer(1,5)); continue
    # table
    if s.startswith('|') and s.endswith('|'):
        rows = []
        while i < len(lines) and lines[i].strip().startswith('|'):
            cells = [c.strip() for c in lines[i].strip().strip('|').split('|')]
            rows.append(cells); i += 1
        add_table(rows); continue
    # titres
    if s.startswith('### '):
        flow.append(Paragraph(inline(s[4:]), styles['h2'])); i += 1; continue
    if s.startswith('## '):
        flow.append(Paragraph(inline(s[3:]), styles['h1'])); i += 1; continue
    if s.startswith('# '):
        flow.append(Paragraph(inline(s[2:]), styles['title'])); i += 1; continue
    # règle horizontale
    if s == '---':
        flow.append(Spacer(1,3)); flow.append(HRFlowable(width='100%', thickness=0.6, color=CODEBORDER)); flow.append(Spacer(1,3)); i += 1; continue
    # citation
    if s.startswith('>'):
        flow.append(Paragraph(inline(s.lstrip('> ').strip()), styles['quote'])); i += 1; continue
    # listes
    m = re.match(r'^(\d+)\.\s+(.*)', s)
    if s.startswith('- ') or s.startswith('* '):
        flow.append(Paragraph(inline(s[2:]), styles['li'], bulletText='•')); i += 1; continue
    if m:
        flow.append(Paragraph(inline(m.group(2)), styles['li'], bulletText=m.group(1)+'.')); i += 1; continue
    # ligne vide
    if s == '':
        flow.append(Spacer(1,3)); i += 1; continue
    # paragraphe
    flow.append(Paragraph(inline(s), styles['body'])); i += 1

doc = SimpleDocTemplate(OUT, pagesize=A4, leftMargin=1.5*cm, rightMargin=1.5*cm,
                        topMargin=1.6*cm, bottomMargin=1.5*cm,
                        title="Déploiement API MadMen")
doc.build(flow)
print("PDF généré :", OUT)
