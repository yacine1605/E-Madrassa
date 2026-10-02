"""Render the validated middle-school plan as a standalone, printable HTML file."""
from collections import Counter
from html import escape
import json
from pathlib import Path

import generate_middle_timetables as model

ROOT = Path(__file__).resolve().parent
PLAN_PATH = ROOT / "middle_timetables_2026_2027.json"
OUTPUT = ROOT.parent / "docs" / "emplois-du-temps-moyen-2026-2027.html"

STYLE = """
:root{font-family:Segoe UI,Arial,sans-serif;color:#172b40;background:#edf2f6;font-synthesis:none}
*{box-sizing:border-box}body{margin:0}.wrap{max-width:1480px;margin:auto;padding:30px}
h1,h2,h3,p{margin-top:0}h1{font-size:30px;letter-spacing:-.7px;margin-bottom:10px}
.eyebrow{color:#43667d;letter-spacing:2px;font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:7px}
.intro,.sheet,.rooms{background:#fff;border:1px solid #d6e0e8;border-radius:15px;padding:24px;margin-bottom:24px}
.intro p{max-width:1080px;line-height:1.6}.muted{color:#566d80}.pills,.nav,.legend{display:flex;gap:9px;flex-wrap:wrap}
.pills span{background:#eaf2f8;border:1px solid #d4e1eb;border-radius:20px;padding:7px 12px;font-size:13px}
.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:20px 0}.actions a,button{border:0;border-radius:8px;background:#183e59;color:white;padding:11px 16px;text-decoration:none;font:600 14px inherit;cursor:pointer}
.nav{margin-bottom:20px}.nav a{padding:9px 12px;background:#f2f6fa;color:#1f4966;border-radius:7px;text-decoration:none;font-weight:600}
.sheet{scroll-margin-top:20px}.sheet-head{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:14px}
.sheet-head h2{font-size:25px;margin:0}.load{text-align:right;font-size:13px;line-height:1.55}.load strong{font-size:22px;color:#183e59}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;table-layout:fixed}
.grid{min-width:920px}.grid th,.grid td{border:1px solid #d3dfe8;padding:9px 10px;vertical-align:top}
.grid thead th{background:#1d405b;color:#fff;font-size:14px;padding:12px}.grid .time{width:115px;background:#edf3f7;text-align:left;font-size:12px;white-space:nowrap}
.grid thead .time{background:#1d405b;color:#fff}
.cell-title{font-size:14px;font-weight:650;line-height:1.25}.detail{font-size:11px;line-height:1.35;margin-top:4px;color:#476074}
.td{background:#eee7fa}.td .cell-title{color:#4b2e79}.td .detail{color:#4b3c65}.pe{background:#e1f3e8}.pe .cell-title{color:#20543a}
.lab{background:#def2f7}.lab .cell-title{color:#075e73}.practical{background:#fff0d4}.practical .cell-title{color:#734a06}
.light{background:#faf8f2}.free,.off,.lunch{background:#f0f3f6;color:#687786}.lunch td{text-align:center;padding:6px;font-size:12px}
.legend{font-size:12px;margin:13px 0}.legend span{padding:6px 9px;border-radius:5px}.note{font-size:12px;line-height:1.45;margin:9px 0}
.reference{margin-top:12px}.reference th,.reference td{border:1px solid #dce4eb;text-align:center;padding:6px 3px;font-size:10px}
.reference th{background:#edf3f7}.reference .row-title{width:95px;text-align:left}.reference caption{text-align:left;font-size:11px;font-weight:700;padding-bottom:6px}
.footer{font-size:11px;line-height:1.5;color:#586f80;margin-top:9px}.rooms table th,.rooms table td{border:1px solid #dce4eb;padding:8px;font-size:12px}
.rooms th{background:#edf3f7}.rooms h2{font-size:22px}.rooms .note{margin-bottom:14px}.empty{color:#8493a0}
@media(max-width:700px){.wrap{padding:12px}.intro,.sheet,.rooms{padding:15px}.sheet-head{align-items:flex-start}.sheet-head h2{font-size:21px}h1{font-size:25px}.load strong{font-size:19px}}
@media print{
@page{size:A4 landscape;margin:9mm}*{print-color-adjust:exact;-webkit-print-color-adjust:exact}
:root{background:#fff}body{margin:0}.wrap{padding:0;max-width:none}.intro,.nav,.rooms{display:none}
.sheet{border:0;border-radius:0;padding:0;margin:0;break-after:page;break-inside:avoid}.sheet:last-of-type{break-after:auto}
.sheet-head{margin-bottom:8px}.sheet-head h2{font-size:20px}.eyebrow{font-size:9px;margin-bottom:4px}.load{font-size:10px}.load strong{font-size:17px}
.table-wrap{overflow:visible}.grid{min-width:0}.grid .time{width:87px;font-size:10px}.grid thead th{padding:8px;font-size:11px}
.grid th,.grid td{padding:7px}.cell-title{font-size:11px}.detail{font-size:9px;margin-top:3px}.lunch td{padding:4px;font-size:10px}
.legend{font-size:9px;margin:8px 0}.legend span{padding:4px 7px}.note{font-size:9px;margin:6px 0}
.reference{margin-top:8px}.reference th,.reference td{padding:4px 2px;font-size:8px}.reference caption{font-size:9px;padding-bottom:4px}
.reference .row-title{width:78px}.footer{font-size:8px;margin-top:6px}
}
"""


def coefficient_table(level):
    subjects = ("AR", "FR", "EN", "MATH", "NS", "PH", "HG", "ISLAM", "CIVIC", "ART", "PE", "CS")
    short = {"AR":"Arabe", "FR":"Français", "EN":"Anglais", "MATH":"Maths", "NS":"Sc. naturelles", "PH":"Physique / techno",
             "HG":"Histoire / géo", "ISLAM":"Éd. islamique", "CIVIC":"Éd. civique", "ART":"Arts / musique", "PE":"EPS", "CS":"Informatique"}
    hours = []
    coefs = []
    for subject in subjects:
        if subject == "HG":
            hours.append("1 + 1")
            coefs.append(str(model.HISTORY_GEOGRAPHY_COEFFICIENT[level - 1]))
        else:
            lecture, _, coef = model.SOURCE[subject][level - 1]
            hours.append(str(lecture))
            coefs.append(f"({coef})" if subject == "CS" else str(coef))
    result = '<table class="reference"><caption>Volumes hebdomadaires hors TD et coefficients</caption><tr><th class="row-title">Matière</th>'
    result += ''.join('<th>'+escape(short[subject])+'</th>' for subject in subjects)+'</tr>'
    result += '<tr><th class="row-title">Cours / TP · h</th>'+''.join('<td>'+h+'</td>' for h in hours)+'</tr>'
    result += '<tr><th class="row-title">Coefficient</th>'+''.join('<td>'+c+'</td>' for c in coefs)+'</tr></table>'
    main_total = sum(int(c) for c in coefs[:-1])
    hg = '3 au total : histoire 1 + géographie 2' if level == 4 else '2 partagé entre histoire et géographie'
    result += f'<div class="footer">Somme des coefficients principaux : <b>{main_total}</b>. Histoire / géographie : {hg}, compté une seule fois. Informatique : 1 h supplémentaire, coefficient (1) hors total principal.<br>'
    result += 'Sciences naturelles et physique/technologie : chacune 1 h de cours + 1 h de TP. Volumes et coefficients : données fournies, annexe à la décision du 27 juillet 2026.</div>'
    return result


def cell(session):
    subject, kind = session['subject'], session['kind']
    style = 'td' if kind == 'td' else 'free' if kind == 'free' else 'pe' if subject == 'PE' else 'lab' if subject == 'CS' else 'practical' if kind == 'practical' else 'light' if subject in model.LIGHT else ''
    title = model.NAMES[subject] + (' · TP' if kind == 'practical' else '')
    details = []
    if kind == 'td':
        groups = session['groups']
        if groups['A'] == groups['B']:
            details.append('Chaque semaine : G1 '+model.NAMES[groups['A']['G1']]+' · G2 '+model.NAMES[groups['A']['G2']])
        else:
            for week, name in [('A','Sem. 1'),('B','Sem. 2')]:
                details.append(name+' : G1 '+model.NAMES[groups[week]['G1']]+' · G2 '+model.NAMES[groups[week]['G2']])
    elif session['room']:
        details.append(session['room'])
    elif kind == 'free':
        details.append('27 h de cours et TD cette semaine')
    return '<td class="'+style+'"><div class="cell-title">'+escape(title)+'</div>'+''.join('<div class="detail">'+escape(d)+'</div>' for d in details)+'</td>'


def timetable(table, plan):
    level = table['level']
    heading = f"{level}AM · {'Variante' if plan['independent_variants'] else 'Classe'} {table['section']}"
    td = 3 if level == 4 else 2
    base = sum(rows[level-1][0] for subject, rows in model.SOURCE.items() if subject != 'CS')
    result = f'<section class="sheet" id="{table["id"]}"><header class="sheet-head"><div><div class="eyebrow">Enseignement moyen · 2026 / 2027</div><h2>{heading}</h2></div><div class="load"><strong>{table["weekly_hours"]} h / semaine</strong><br>{base} h cours / TP + {td} h TD + 1 h informatique</div></header>'
    lookup = {(s['block'],s['day'],s['period']):s for s in table['sessions']}
    result += '<div class="table-wrap"><table class="grid"><thead><tr><th class="time">Horaire</th>'+''.join('<th>'+escape(day)+'</th>' for day in plan['days'])+'</tr></thead><tbody>'
    for period, (start, end) in enumerate(plan['morning']):
        result += '<tr><th class="time">'+start+'–'+end+'</th>'+''.join(cell(lookup['morning',day,period]) for day in range(5))+'</tr>'
    result += '<tr class="lunch"><th class="time">12:00–13:00</th><td colspan="5">Pause déjeuner · le mardi, fin des cours à 12:00</td></tr>'
    for period, (start, end) in enumerate(plan['afternoon']):
        result += '<tr><th class="time">'+start+'–'+end+'</th>'
        for day in range(5):
            if day == 2:
                if period == 0:
                    result += '<td class="off" rowspan="2"><div class="cell-title">Après-midi libre</div></td>'
            else:
                result += cell(lookup['afternoon',day,period])
        result += '</tr>'
    result += '</tbody></table></div><div class="legend"><span class="td">TD / أ.م · demi-groupes</span><span class="pe">EPS · gymnase partagé</span><span class="lab">Salle informatique</span><span class="practical">Travaux pratiques</span></div>'
    result += '<p class="note"><b>TD :</b> les semaines 1 et 2 se répètent en alternance. Chaque groupe reçoit 1 h de français et 1 h d’anglais sur deux semaines (0,5 h/semaine pour chaque langue). '
    result += ('Les deux TD arabe/mathématiques sont inversés dans la même semaine : chaque groupe suit 1 h d’arabe et 1 h de maths chaque semaine.' if level == 4 else 'L’arabe et les maths alternent également sur deux semaines : 0,5 h/semaine de chaque matière par groupe.')+'</p>'
    if plan['morning_pe']:
        result += '<p class="note"><b>Adaptation retenue :</b> 1 h d’EPS le matin et 1 h l’après-midi, sur deux jours distincts ; le TP de sciences naturelles passe l’après-midi. Un seul gymnase pour les huit classes.</p>'
    result += coefficient_table(level)+'</section>'
    return result


def room_audit(plan):
    lookup = {}
    for table in plan['timetables']:
        for s in table['sessions']:
            if s['room']:
                lookup[table['resource_batch'],s['room'],s['day'],s['start']] = table['id']
    result = '<aside class="rooms"><h2>Occupation des espaces partagés</h2><p class="note">Contrôle commun aux huit classes : aucune double réservation du gymnase ou de la salle informatique.</p>'
    batches = model.SECTIONS if plan['independent_variants'] else ('all',)
    for batch in batches:
        for room in plan['gym_rooms']+plan['computer_rooms']:
            result += '<h3>'+escape(room)+(f' · variante {batch}' if plan['independent_variants'] else '')+'</h3><table><tr><th>Horaire</th>'+''.join('<th>'+d+'</th>' for d in plan['days'])+'</tr>'
            for start,end in plan['morning']+plan['afternoon']:
                result += '<tr><th>'+start+'–'+end+'</th>'+''.join('<td>'+escape(lookup.get((batch,room,day,start),'—'))+'</td>' for day in range(5))+'</tr>'
            result += '</table>'
    return result+'</aside>'


def render(plan):
    model.validate(plan)
    title = '8 emplois du temps · Enseignement moyen'
    html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'+title+'</title><style>'+STYLE+'</style></head><body><main class="wrap">'
    html += '<header class="intro"><div class="eyebrow">Année scolaire 2026 / 2027</div><h1>'+title+'</h1><p>Deux classes par année, de la 1AM à la 4AM. Cours de 8 h à 12 h, puis de 13 h à 15 h ; mardi après-midi libre. En 3AM, jeudi de 11 h à 12 h : heure libre.</p>'
    if plan['morning_pe']:
        html += '<p><b>Organisation avec un seul gymnase :</b> une heure d’EPS le matin et une l’après-midi pour chaque classe, sur deux jours différents. En échange, le TP de sciences naturelles est placé l’après-midi. Cette adaptation permet de conserver tous les volumes demandés pour les huit classes.</p>'
    html += '<div class="pills"><span>1AM, 2AM, 4AM : 28 h</span><span>3AM : 27 h</span><span>TD en demi-groupes · cycle de 2 semaines</span><span>Gymnase et informatique sans conflit</span></div>'
    html += '<p class="note">Les contrôles portent sur les classes et les espaces partagés. Les enseignants n’ont pas été fournis et ne sont pas affectés dans ces grilles.</p><div class="actions"><button onclick="window.print()">Imprimer / PDF</button><a href="emplois-du-temps-moyen-2026-2027.pdf">Télécharger les 8 grilles en PDF</a></div></header>'
    html += '<nav class="nav" aria-label="Choisir une classe">'+''.join('<a href="#'+t['id']+'">'+t['id']+'</a>' for t in plan['timetables'])+'</nav>'
    html += ''.join(timetable(table,plan) for table in plan['timetables'])
    html += room_audit(plan)+'</main></body></html>'
    return html


if __name__ == '__main__':
    plan = json.loads(PLAN_PATH.read_text(encoding='utf-8'))
    OUTPUT.write_text(render(plan),encoding='utf-8')
    print(OUTPUT)
