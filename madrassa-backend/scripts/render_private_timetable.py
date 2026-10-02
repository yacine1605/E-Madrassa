"""Printable weekly review, by classroom and teacher."""
import json
from pathlib import Path
from html import escape
from collections import Counter

root=Path(__file__).resolve().parent
plan=json.loads((root/'private_timetable.json').read_text(encoding='utf-8'))
names={
 'T-2026-PRE-A-AR':'كوثر عزوز','T-2026-PRE-B-AR':'إكرام بن ناصر',
 'T-2026-PRI-1AP-A-AR':'أمينة بن صالح','T-2026-PRI-1AP-B-AR':'ليلى حميدي',
 'T-2026-PRI-2AP-A-AR':'نادية براهيمي','T-2026-PRI-2AP-B-AR':'أحلام درار',
 'T-2026-PRI-3AP-A-AR':'إيمان رحماني','T-2026-PRI-3AP-B-AR':'كريمة شريف',
 'T-2026-PRI-4AP-A-AR':'حنان زروقي','T-2026-PRI-5AP-A-AR':'حنين غربي',
 'T-2026-PRI-1AP-A-FR':'خديجة بوزيد','T-2026-PRI-2AP-A-FR':'سارة منصوري',
 'T-2026-PRI-4AP-A-FR':'لمياء عياد','T-2026-PRI-1AP-A-EN':'فاطمة الزهراء بن عمر',
 'T-2026-PRI-2AP-A-EN':'مريم حداد','T-PLAN-ES-01':'Espagnol — nom à renseigner',
 'T-PLAN-SPORT-01':'Sport — nom à renseigner'}
subjects={'PRE-AR':'Langage arabe','PRE-MATH':'Éveil mathématique','PRE-ART':'Arts / motricité fine',
 'PRE-EVEIL':'Découverte / jeux éducatifs','PRE-REPOS':'Repos / activités calmes','PRE-ATELIER':'Atelier / activités guidées',
 'PRI-AR-LECT':'Arabe','PRI-MATH':'Mathématiques','PRI-ISLAM':'Éducation islamique',
 'PRI-ART':'Arts','PRI-SCI-TECH':'Sciences / technologie','PRI-HIST':'Histoire',
 'PRI-HG':'Histoire / géographie','PRI-FR':'Français','PRI-EN':'Anglais','PRI-ES':'Espagnol','PRI-SPORT':'Sport / motricité',
 'PRI-CIV':'Éducation civique','PRI-ATELIER':'Atelier / soutien'}
html=['''<!doctype html><html lang="fr"><meta charset="utf-8"><title>Emplois du temps — 2026/2027</title>
<style>body{font:15px system-ui;margin:32px;color:#172b40}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #cbd5e1;padding:10px;vertical-align:top}th{background:#e8f1f5}small{display:block;margin-top:3px}h1{font-size:25px}h2{font-size:21px}.pause{background:#f1f5f9;color:#596777}.lesson{padding:6px 0;border-bottom:1px solid #e2e8f0}.lesson b{display:block;color:#31546e}section{margin:35px 0;break-before:page}button{padding:10px 20px}@media print{button{display:none}body{margin:0;font-size:9px}th,td{padding:5px}section{margin:0}.lesson{padding:4px 0}@page{size:A4 landscape;margin:12mm}}</style>
<style>.support-english{background:#dbeafe;border-left:4px solid #2563eb;color:#1e3a8a}.support-french{background:#fef3c7;border-left:4px solid #b45309;color:#78350f}.lesson.support-english,.lesson.support-french{padding:6px 8px;margin:3px 0;border-radius:4px}.support-english b,.support-french b{color:inherit}.legend{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.legend span{padding:8px 12px;border-radius:4px}*{print-color-adjust:exact;-webkit-print-color-adjust:exact}</style>
<h1>École privée · Emplois du temps 2026/2027</h1><p>11 classes · 17 enseignants · séances de 30, 45 ou 60 minutes.</p>
<p>Dimanche, lundi, mercredi et jeudi : 8 h 30–12 h et 13 h–16 h. Mardi : 8 h 30–12 h 30, après-midi libre. Récréation à 10 h 30 : 0 min, simple indication sans créneau réservé.<br>
Durées par matière selon les valeurs fournies par l’école. Espagnol : 1 h dans chaque classe. Français et anglais dès le préscolaire.<br>
4AP : les matières fournies totalisent 22 h 30, au lieu des 25 h 30 annoncées. 5AP : 23 h 15. Aucun arrondi des fractions d’heure.<br>
Au primaire et au préscolaire, les ateliers/soutien comprennent 2 h d’anglais par classe et le reste en français, en plus des cours de langue. Chaque atelier est accolé à un cours de la même langue et assuré par son enseignant. Total : 30 h. Récréation : 0 min.<br>
Import annuel en attente du calendrier des fermetures ; période en base : 21/09/2026–08/06/2027.</p><button onclick="print()">Imprimer / PDF</button>''']
html.append('<div class="legend"><span class="support-english">Atelier / soutien · Anglais — 2 h par classe</span><span class="support-french">Atelier / soutien · Français — temps restant</span></div>')
def duration_label(minutes):
 return str(minutes//60)+' h'+(' '+str(minutes%60).zfill(2) if minutes%60 else '') if minutes>=60 else str(minutes)+' min'

def render_lesson(session, kind, start, end):
 support=session['activity']=='support'
 colour=(' support-english' if session['subject']=='PRI-EN' else ' support-french') if support else ''
 label='Atelier / soutien' if support else subjects[session['subject']]
 language='<small>'+escape(subjects[session['subject']])+'</small>' if support else ''
 return ('<div class="lesson'+colour+'"><b>'+max(start,session['start'])+'–'+min(end,session['end'])+'</b>'
         +escape(label)+(' (suite)' if session['start']<start else '')+language+'<small>'
         +escape(names[session['teacher']] if kind=='class_code' else session['class_code'])+'</small></div>')

times=[('08:30','10:30'),('10:30','10:30'),('10:30','12:00'),('12:00','12:30'),('12:30','13:00'),('13:00','16:00')]
for kind,items in [('class_code',plan['classes']),('teacher',list(names))]:
 for item in items:
  title='Classe '+item if kind=='class_code' else names[item]
  sessions=[s for s in plan['sessions'] if s[kind]==item]
  html.append('<section><h2>'+escape(title)+'</h2><table><tr><th>Horaire</th>'+''.join('<th>'+d+'</th>' for d in ['Dimanche','Lundi','Mardi','Mercredi','Jeudi'])+'</tr>')
  for start,end in times:
   html.append('<tr><th>'+(start if start==end else start+'–'+end)+'</th>')
   for day in range(5):
    if day!=2 and start=='12:30': continue  # Lunch spans both half-hour rows.
    cell_sessions=sorted([s for s in sessions if start!=end and s['day']==day and s['start']<end and s['end']>start],key=lambda s:s['start'])
    if start==end: cell='Récréation — 0 min'
    elif day!=2 and start=='12:00': cell='Déjeuner<small>12:00–13:00</small>'
    elif day==2 and start=='12:30': cell='Fin des cours à 12:30'
    elif day==2 and start>='13:00': cell='Libre'
    elif not cell_sessions: cell='—'
    else:
     cell=''.join(render_lesson(s,kind,start,end) for s in cell_sessions)
    html.append('<td'+(' rowspan="2"' if day!=2 and start=='12:00' else '')+(' class="pause"' if not cell_sessions else '')+'>'+cell+'</td>')
   html.append('</tr>')
  totals=Counter(); support_totals=Counter()
  for s in sessions:
   totals[s['subject']]+=s['minutes']
   if s['activity']=='support': support_totals[s['subject']]+=s['minutes']
  summary=', '.join(subjects[k]+': '+duration_label(v)+(' (dont atelier/soutien '+duration_label(support_totals[k])+')' if support_totals[k] else '') for k,v in totals.items())
  html.append('</table><p>Total du programme : '+duration_label(sum(totals.values()))+' par semaine, hors récréation · '+escape(summary)+'</p></section>')
html.append('</html>')
output=root.parent/'docs'/'emplois-du-temps-2026-2027.html'
output.write_text(''.join(html),encoding='utf-8')
print(output)
