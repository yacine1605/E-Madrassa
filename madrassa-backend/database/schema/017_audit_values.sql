-- FR-AUD-01 / BR-09: an update records the old and new value of each changed field,
-- the reason given, and the request it came from. Personal data and bulky
-- documents are masked (BR-17): the field name is kept, the value is not.
ALTER TABLE school.audit_events
 ADD COLUMN before jsonb,
 ADD COLUMN after jsonb,
 ADD COLUMN reason text,
 ADD COLUMN request_id text,
 ADD COLUMN ip text;

CREATE OR REPLACE FUNCTION school.audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE row_data jsonb; old_data jsonb; changed jsonb; before_values jsonb; after_values jsonb; masked text[];
BEGIN
 row_data:=CASE WHEN TG_OP='DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
 old_data:=CASE WHEN TG_OP='INSERT' THEN NULL ELSE to_jsonb(OLD) END;
 SELECT COALESCE(jsonb_agg(key),'[]') INTO changed FROM jsonb_each(row_data) WHERE TG_OP<>'UPDATE' OR value IS DISTINCT FROM old_data->key;
 masked:=ARRAY['first_name','last_name','birth_date','phone','email','address','matricule','guardian_name','guardian_phone',
 'previous_school','observation','body','payload','rendered','rules','calculation'];
 IF TG_TABLE_NAME IN ('guardians','staff') THEN masked:=masked||ARRAY['name']; END IF;
 IF TG_OP='UPDATE' THEN
 SELECT jsonb_object_agg(key,CASE WHEN key=ANY(masked) THEN to_jsonb('[masqué]'::text) ELSE old_data->key END),
 jsonb_object_agg(key,CASE WHEN key=ANY(masked) THEN to_jsonb('[masqué]'::text) ELSE value END)
 INTO before_values,after_values FROM jsonb_each(row_data) WHERE value IS DISTINCT FROM old_data->key;
 END IF;
 INSERT INTO school.audit_events(tenant_id,actor_id,entity,entity_id,action,changed_fields,before,after,reason,request_id,ip)
 VALUES((row_data->>'tenant_id')::uuid,school.actor(),TG_TABLE_NAME,(row_data->>'id')::uuid,TG_OP,changed,before_values,after_values,
 nullif(current_setting('app.reason',true),''),nullif(current_setting('app.request_id',true),''),nullif(current_setting('app.ip',true),''));
 RETURN COALESCE(NEW,OLD);
END $$;

-- Tables added after the base schema without an audit trigger.
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.teacher_assignments FOR EACH ROW EXECUTE FUNCTION school.audit();
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.teacher_qualifications FOR EACH ROW EXECUTE FUNCTION school.audit();
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.report_card_terms FOR EACH ROW EXECUTE FUNCTION school.audit();
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.report_card_observations FOR EACH ROW EXECUTE FUNCTION school.audit();
