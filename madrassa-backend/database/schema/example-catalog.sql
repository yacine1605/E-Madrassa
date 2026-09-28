-- Example configuration, not an official curriculum. Execute with psql variables:
-- tenant_id and user_id of an active member; runtime INSERT grants are sufficient.
BEGIN;
SELECT set_config('app.user_id', :'user_id', true), set_config('app.tenant_id', :'tenant_id', true);
INSERT INTO school.programs(tenant_id,code,name,kind) VALUES
 (:'tenant_id','PRIMAIRE','Primaire','school'),
 (:'tenant_id','MOYEN','Moyen','school'),
 (:'tenant_id','SECONDAIRE','Secondaire','school'),
 (:'tenant_id','ANGLAIS','Anglais','language'),
 (:'tenant_id','INFORMATIQUE','Formation informatique','vocational');
INSERT INTO school.levels(tenant_id,program_id,code,name,position)
 SELECT p.tenant_id,p.id,v.code,v.code,v.position FROM school.programs p
 JOIN (VALUES ('PRIMAIRE','1AP',1),('PRIMAIRE','2AP',2),('PRIMAIRE','3AP',3),('PRIMAIRE','4AP',4),('PRIMAIRE','5AP',5),
 ('MOYEN','1AM',1),('MOYEN','2AM',2),('MOYEN','3AM',3),('MOYEN','4AM',4),
 ('SECONDAIRE','1AS',1),('SECONDAIRE','2AS',2),('SECONDAIRE','3AS',3),
 ('ANGLAIS','A1',1),('ANGLAIS','A2',2),('ANGLAIS','B1',3),('ANGLAIS','B2',4),
 ('INFORMATIQUE','INITIATION',1),('INFORMATIQUE','AVANCE',2)) AS v(program,code,position)
 ON p.code=v.program WHERE p.tenant_id=school.tenant();
COMMIT;
