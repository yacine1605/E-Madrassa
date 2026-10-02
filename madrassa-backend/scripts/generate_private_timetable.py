"""Schedule exact supplied minutes with private-school additions, in 15-minute units."""
import json
from pathlib import Path
from collections import defaultdict, Counter
import numpy as np
from scipy.optimize import milp, Bounds, LinearConstraint
from scipy.sparse import coo_matrix

ROOT = Path(__file__).resolve().parent
REFERENCE = json.loads((ROOT.parent/'config/primary_weekly_minutes.json').read_text(encoding='utf-8'))
CLASSES = ['PRE-A','PRE-B','1AP-A','1AP-B','2AP-A','2AP-B','3AP-A','3AP-B','4AP-A','4AP-B','5AP-A']
GENERAL = {c:'T-2026-'+('PRE-'+c[-1] if c.startswith('PRE') else 'PRI-'+c)+'-AR' for c in CLASSES if c!='4AP-B'}
FRENCH = ['T-2026-PRI-1AP-A-FR','T-2026-PRI-2AP-A-FR','T-2026-PRI-4AP-A-FR']
ENGLISH = ['T-2026-PRI-1AP-A-EN','T-2026-PRI-2AP-A-EN']
SHARED = {'PRI-AR-LECT':'1AP-A','PRI-MATH':'1AP-B','PRI-ISLAM':'2AP-B','PRI-ART':'3AP-B',
          'PRI-SCI-TECH':'2AP-A','PRI-HG':'4AP-A','PRI-CIV':'2AP-A'}
WINDOWS = {d:[(510,630),(630,750 if d==2 else 720)]+([] if d==2 else [(780,960)]) for d in range(5)}

def base_minutes_for(code):
    if code.startswith('PRE'):
        minutes=dict(REFERENCE['preschool'])
        if 'PRESCOLAIRE' in REFERENCE['language_support']['cycles']: minutes.pop('PRE-ATELIER',None)
        return minutes
    grade=code[0]
    minutes=dict(REFERENCE['primary'][grade])
    for subject,duration in REFERENCE['private_supplements'][grade].items():
        if subject in minutes: raise ValueError('Supplement must not replace core hours')
        minutes[subject]=duration
    return minutes

def support_minutes_for(code):
    cycle='PRESCOLAIRE' if code.startswith('PRE') else 'PRIMAIRE'
    if cycle not in REFERENCE['language_support']['cycles']: return {}
    spare=REFERENCE['school_week_minutes']-sum(base_minutes_for(code).values())
    english=REFERENCE['language_support']['english_minutes']
    if spare<english: raise ValueError('Not enough workshop time for English support')
    return {subject:minutes for subject,minutes in {'PRI-EN':english,'PRI-FR':spare-english}.items() if minutes}

def minutes_for(code):
    minutes=base_minutes_for(code)
    for subject,duration in support_minutes_for(code).items(): minutes[subject]=minutes.get(subject,0)+duration
    return minutes

def chunks(minutes, language_course=False):
    if language_course:
        if minutes%30: raise ValueError('Language course volume must fit half-hour units')
        return Counter({30:minutes//30})
    pieces=[]
    while minutes>120: pieces.append(60); minutes-=60
    pieces += {30:[30],45:[45],60:[60],75:[45,30],90:[60,30],105:[60,45],120:[60,60]}[minutes]
    return Counter(pieces)

def build():
    lessons=[]; activities=[]
    for i,code in enumerate(CLASSES):
        support=support_minutes_for(code)
        for subject,minutes in minutes_for(code).items():
            teacher=(FRENCH[min(i//4,2)] if subject=='PRI-FR' else ENGLISH[0 if i<6 else 1] if subject=='PRI-EN' else
                     'T-PLAN-ES-01' if subject=='PRI-ES' else 'T-PLAN-SPORT-01' if subject=='PRI-SPORT' else
                     GENERAL[SHARED[subject]] if code=='4AP-B' else GENERAL[code])
            if code=='1AP-A' and subject=='PRI-ART': teacher=GENERAL['3AP-A']
            lesson=dict(class_code=code,subject=subject,teacher=teacher,minutes=minutes,hours=minutes/60,
                        support_minutes=support.get(subject,0),course_minutes=minutes-support.get(subject,0))
            li=len(lessons); lessons.append(lesson)
            for activity,duration in [('course',lesson['course_minutes']),('support',lesson['support_minutes'])]:
                if duration: activities.append(dict(class_code=code,subject=subject,teacher=teacher,activity=activity,minutes=duration,lesson_index=li))
    variables=[]; groups=defaultdict(list); costs=[]; counts={}
    course_starts=defaultdict(list); course_ends=defaultdict(list); support_variables=[]
    rng=np.random.default_rng(2026)
    for ai,lesson in enumerate(activities):
        language_course=lesson['activity']=='course' and lesson['subject'] in ['PRI-FR','PRI-EN']
        for duration,count in chunks(lesson['minutes'],language_course).items():
            counts[ai,duration]=count
            for day,windows in WINDOWS.items():
                for first,last in windows:
                    for start in range(first,last-duration+1,15):
                        if lesson['teacher']==GENERAL['PRE-A'] and day==1 and start<748 and start+duration>688: continue
                        if lesson['subject']=='PRE-REPOS' and not (start==780 or (day==2 and start==660)): continue
                        vi=len(variables); variables.append((ai,day,start,duration))
                        groups['count',ai,duration].append((vi,1))
                        key=(lesson['class_code'],lesson['subject'],day)
                        if language_course:
                            course_starts[*key,start].append(vi)
                            course_ends[*key,start+duration].append(vi)
                        if lesson['activity']=='support': support_variables.append((vi,key,start,duration))
                        for minute in range(start,start+duration,15):
                            groups['class',lesson['class_code'],day,minute].append((vi,1))
                            groups['teacher',lesson['teacher'],day,minute].append((vi,1))
                        groups['daily',lesson['lesson_index'],day].append((vi,duration))
                        academic=lesson['subject'] in ['PRI-AR-LECT','PRI-MATH']
                        costs.append((3 if academic and start>=780 else 0)+rng.uniform(0,.01))
    # Each support session touches a course in its own language and class.
    # The shared teacher and resource constraints also apply to these sessions.
    for vi,key,start,duration in support_variables:
        adjacent=course_ends.get((*key,start),[])+course_starts.get((*key,start+duration),[])
        groups['adjacent',vi]=[(vi,-1)]+[(other,1) for other in adjacent]
    rows=[]; cols=[]; values=[]; low=[]; high=[]
    for key,entries in groups.items():
        if key[0]=='count': lo=hi=counts[key[1],key[2]]
        elif key[0]=='class': lo=hi=1
        elif key[0]=='teacher': lo,hi=0,1
        elif key[0]=='adjacent': lo,hi=0,np.inf
        else:
            sub=lessons[key[1]]['subject']
            lo,hi=0,(180 if sub in ['PRI-AR-LECT','PRE-AR','PRI-FR','PRI-EN'] else 120 if sub in ['PRI-MATH','PRE-MATH'] else 60)
            if sub=='PRI-AR-LECT': lo=60
            if sub=='PRI-MATH': lo=30
            if sub=='PRE-REPOS': lo=hi=60
        row=len(low); low.append(lo); high.append(hi)
        for vi,weight in entries: rows.append(row); cols.append(vi); values.append(weight)
    matrix=coo_matrix((values,(rows,cols)),shape=(len(low),len(variables))).tocsc()
    result=milp(np.array(costs),integrality=np.ones(len(variables)),bounds=Bounds(0,1),
                constraints=LinearConstraint(matrix,low,high),options={'time_limit':50,'mip_rel_gap':.5})
    if result.x is None: raise RuntimeError(result.message)
    fmt=lambda m:f'{m//60:02}:{m%60:02}'
    sessions=[]
    for vi,(ai,day,start,duration) in enumerate(variables):
        if result.x[vi]>.5:
            l=activities[ai]
            sessions.append({k:l[k] for k in ['class_code','subject','teacher','activity']} | dict(day=day,start=fmt(start),end=fmt(start+duration),minutes=duration))
    plan={'schema_version':2,'timezone':'Africa/Algiers','time_unit_minutes':15,'weekly_minutes':REFERENCE['school_week_minutes'],
          'recess':{'time':'10:30','minutes':0},
          'note':'Volumes par matière fournis par l’école, compléments privés distincts. Récréation à 10h30 : 0 minute, simple repère sans créneau réservé. Mardi : 8h30–12h30, après-midi libre. Les autres jours : déjeuner 12h–13h.',
          'reference_totals_minutes':{g:sum(m.values()) for g,m in REFERENCE['primary'].items()},
          'reference_notes':REFERENCE['notes'],'classes':CLASSES,'lessons':lessons,
          'sessions':sorted(sessions,key=lambda s:(s['class_code'],s['day'],s['start']))}
    validate(plan)
    return plan

def validate(plan):
    assert plan['recess']=={'time':'10:30','minutes':0}
    occupied=set(); totals=Counter(); support_totals=Counter(); teachers=set()
    for s in plan['sessions']:
        start=sum(int(x)*n for x,n in zip(s['start'].split(':'),[60,1]))
        end=sum(int(x)*n for x,n in zip(s['end'].split(':'),[60,1]))
        assert end-start==s['minutes'] and s['minutes'] in [30,45,60]
        assert any(a<=start<end<=b for a,b in WINDOWS[s['day']])
        for minute in range(start,end,15):
            for kind in ['class_code','teacher']:
                key=(kind,s[kind],s['day'],minute)
                assert key not in occupied, key
                occupied.add(key)
        totals[s['class_code'],s['subject']]+=s['minutes']; teachers.add(s['teacher'])
        assert s['activity'] in ['course','support']
        if s['activity']=='support':
            support_totals[s['class_code'],s['subject']]+=s['minutes']
            assert any(c['activity']=='course' and all(c[k]==s[k] for k in ['class_code','subject','teacher','day'])
                       and (c['end']==s['start'] or c['start']==s['end']) for c in plan['sessions']), 'Support must touch a same-language course'
    assert len(teachers)==17
    for code in CLASSES:
        expected=minutes_for(code)
        assert {s:m for (c,s),m in totals.items() if c==code}==expected
        assert {s:m for (c,s),m in support_totals.items() if c==code}==support_minutes_for(code)
        for day,windows in WINDOWS.items():
            for first,last in windows:
                assert all(('class_code',code,day,m) in occupied for m in range(first,last,15))

if __name__=='__main__':
    plan=build()
    (ROOT/'private_timetable.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2),encoding='utf-8')
    teacher_minutes=Counter()
    for s in plan['sessions']: teacher_minutes[s['teacher']]+=s['minutes']
    print(json.dumps({'sessions':len(plan['sessions']),'reference_totals_hours':{g:m/60 for g,m in plan['reference_totals_minutes'].items()},
                      'teacher_hours':{t:m/60 for t,m in teacher_minutes.items()}},indent=2))
