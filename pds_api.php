<?php
/**
 * PDS API — CRUD for all PDS tables
 * Database: cgmhris
 * Auth: Bearer token via main HRMIS auth or PDS self-service auth
 */
require_once __DIR__ . '/db_auth.php';
require_once __DIR__ . '/pds_db.php';
header('Content-Type: application/json');

$mainUser = validateToken();
$pdsUser = null;
if (!$mainUser) {
    $pdsUser = pds_validate_token();
}
if (!$mainUser && !$pdsUser) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}

$uid    = (int)(($mainUser ?: $pdsUser)['id'] ?? 0);
$uname  = $mainUser
    ? (string)($mainUser['username'] ?? 'HRMIS')
    : (string)($pdsUser['id_number'] ?? 'PDS USER');
$actorRole = $mainUser ? 'admin' : ($pdsUser['role'] ?? 'employee');
$isPdsOnlyUser = ($actorRole !== 'admin');
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// JSON body support
$body = [];
if ($method === 'POST' || $method === 'PUT') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
}
function p($key, $default = '') { global $body; return $body[$key] ?? $_POST[$key] ?? $default; }
function pint($key) { return (int)p($key, 0); }

function ok($data = [])  { echo json_encode(['status'=>'success'] + $data); exit; }
function err($msg, $code=400) { http_response_code($code); echo json_encode(['status'=>'error','message'=>$msg]); exit; }

function requireAdminOnly(): void {
    global $isPdsOnlyUser;
    if ($isPdsOnlyUser) {
        err('Only HR administrators can perform this action.', 403);
    }
}

function enforcePdsOwnership(PDO $conn, int $pds_id): void {
    global $pdsUser;

    if (!$pdsUser || !$pds_id) {
        return;
    }

    $linkedId = (int)($pdsUser['pds_id'] ?? 0);
    if ($linkedId) {
        if ($linkedId !== $pds_id) {
            err('Not authorized for this PDS record.', 403);
        }
        return;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM pds_personal_info
        WHERE id = ?
          AND (
            (employee_no = ? AND ? <> '')
            OR (bio_id = ? AND ? <> '')
          )
        LIMIT 1
    ");
    $stmt->execute([
        $pds_id,
        $pdsUser['employee_no'] ?? '',
        $pdsUser['employee_no'] ?? '',
        $pdsUser['bio_id'] ?? '',
        $pdsUser['bio_id'] ?? '',
    ]);
    if (!$stmt->fetch()) {
        err('Not authorized for this PDS record.', 403);
    }
}

function resetWorkflowToDraft(PDO $conn, int $pds_id): void {
    $conn->prepare("
        UPDATE pds_personal_info
        SET workflow_status = 'verified',
            verified_at = NOW()
        WHERE id = ?
    ")->execute([$pds_id]);
}

function updatePdsUserLink(int $pds_id, string $employeeNo, string $bioId): void {
    global $pdsUser, $pds_conn;

    if (!$pdsUser) {
        return;
    }

    $pds_conn->prepare("
        UPDATE pds_users
        SET pds_id = ?,
            employee_no = COALESCE(NULLIF(?, ''), employee_no),
            bio_id = COALESCE(NULLIF(?, ''), bio_id)
        WHERE id = ?
    ")->execute([
        $pds_id,
        $employeeNo,
        $bioId,
        (int)$pdsUser['id'],
    ]);
}

function audit($conn, $pds_id, $action, $section, $uid, $uname) {
    try {
        $s = $conn->prepare("INSERT INTO pds_audit_log
            (pds_id, bio_id, employee_no, full_name, action, section_updated, changed_by_user_id, changed_by_username, ip_address)
            SELECT ?, bio_id, employee_no, CONCAT(surname,', ',first_name,' ',IFNULL(middle_name,'')),
                   ?, ?, ?, ?, ?
            FROM pds_personal_info WHERE id=?");
        $s->execute([$pds_id, $action, $section, $uid, $uname, $_SERVER['REMOTE_ADDR']??'', $pds_id]);
    } catch(Exception $e) { error_log('audit: '.$e->getMessage()); }
}

switch($action) {

// ── LIST ────────────────────────────────────────────────────────
case 'list':
    $search = '%' . ($_GET['q'] ?? '') . '%';
    if ($isPdsOnlyUser) {
        $linkedId = (int)($pdsUser['pds_id'] ?? 0);
        if (!$linkedId) {
            ok(['data' => []]);
        }
        $stmt = $conn->prepare("
            SELECT p.id, p.bio_id, p.employee_no,
                   p.surname, p.first_name, p.middle_name, p.name_ext,
                   p.sex, p.civil_status, p.birth_date,
                   p.mobile_no, p.email, p.is_active,
                   p.workflow_status, p.submitted_at, p.submitted_by_name,
                   p.verified_at, p.verified_by_name, p.updated_at,
                   (SELECT COUNT(*) FROM pds_attachments a WHERE a.pds_id=p.id AND a.file_type='photo' AND a.is_primary=1 AND a.is_deleted=0) AS has_photo
            FROM pds_personal_info p
            WHERE p.id = ?
              AND (p.surname LIKE ? OR p.first_name LIKE ? OR p.employee_no LIKE ?)
            LIMIT 1
        ");
        $stmt->execute([$linkedId, $search, $search, $search]);
    } else {
        $stmt = $conn->prepare("
            SELECT p.id, p.bio_id, p.employee_no,
                   p.surname, p.first_name, p.middle_name, p.name_ext,
                   p.sex, p.civil_status, p.birth_date,
                   p.mobile_no, p.email, p.is_active,
                   p.workflow_status, p.submitted_at, p.submitted_by_name,
                   p.verified_at, p.verified_by_name, p.updated_at,
                   (SELECT COUNT(*) FROM pds_attachments a WHERE a.pds_id=p.id AND a.file_type='photo' AND a.is_primary=1 AND a.is_deleted=0) AS has_photo
            FROM pds_personal_info p
            WHERE p.surname LIKE ? OR p.first_name LIKE ? OR p.employee_no LIKE ?
            ORDER BY p.surname, p.first_name
            LIMIT 500
        ");
        $stmt->execute([$search, $search, $search]);
    }
    ok(['data' => $stmt->fetchAll()]);

// ── GET FULL PDS ─────────────────────────────────────────────────
case 'get':
    $pds_id = (int)($_GET['pds_id'] ?? 0);
    if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $out = [];

    $out['personal']     = $conn->prepare("SELECT * FROM pds_personal_info WHERE id=?");
    $out['personal']->execute([$pds_id]);
    $out['personal']     = $out['personal']->fetch() ?: null;
    if (!$out['personal']) err('Record not found', 404);

    foreach ([
        'family'      => "SELECT * FROM pds_family_background WHERE pds_id=?",
        'govid'       => "SELECT * FROM pds_government_id WHERE pds_id=?",
        'questionnaire'=> "SELECT * FROM pds_questionnaire WHERE pds_id=?",
    ] as $key => $sql) {
        $s = $conn->prepare($sql); $s->execute([$pds_id]);
        $out[$key] = $s->fetch() ?: null;
    }

    foreach ([
        'children'    => "SELECT * FROM pds_children WHERE pds_id=? ORDER BY sort_order",
        'education'   => "SELECT * FROM pds_education WHERE pds_id=? ORDER BY sort_order",
        'civil'       => "SELECT * FROM pds_civil_service WHERE pds_id=? ORDER BY sort_order",
        'work'        => "SELECT * FROM pds_work_experience WHERE pds_id=? ORDER BY sort_order",
        'voluntary'   => "SELECT * FROM pds_voluntary_work WHERE pds_id=? ORDER BY sort_order",
        'ld'          => "SELECT * FROM pds_learning_development WHERE pds_id=? ORDER BY sort_order",
        'other_skills'=> "SELECT * FROM pds_other_info WHERE pds_id=? AND info_type='Skills' ORDER BY sort_order",
        'other_recog' => "SELECT * FROM pds_other_info WHERE pds_id=? AND info_type='Recognition' ORDER BY sort_order",
        'other_memb'  => "SELECT * FROM pds_other_info WHERE pds_id=? AND info_type='Membership' ORDER BY sort_order",
        'references'  => "SELECT * FROM pds_references WHERE pds_id=? ORDER BY sort_order",
    ] as $key => $sql) {
        $s = $conn->prepare($sql); $s->execute([$pds_id]);
        $out[$key] = $s->fetchAll();
    }

    $os = $conn->prepare("SELECT * FROM pds_office WHERE pds_id=?");
    $os->execute([$pds_id]);
    $out['office'] = $os->fetch() ?: null;

    ok(['data' => $out]);

// ── SAVE PERSONAL INFO ────────────────────────────────────────────
case 'save_personal':
    $pds_id = pint('pds_id');
    if ($pds_id) {
        enforcePdsOwnership($conn, $pds_id);
    }
    $surname = trim(p('surname'));
    $first_name = trim(p('first_name'));
    $birth_date = trim((string)p('birth_date'));
    if (!$surname || !$first_name || !$birth_date) err('Surname, First Name, and Birth Date are required');

    $fields = [
        'bio_id'=>p('bio_id'), 'employee_no'=>p('employee_no'),
        'surname'=>$surname, 'first_name'=>$first_name,
        'middle_name'=>p('middle_name'), 'name_ext'=>p('name_ext'),
        'birth_date'=>$birth_date ?: null, 'birth_place'=>p('birth_place'),
        'sex'=>p('sex'), 'civil_status'=>p('civil_status'),
        'civil_status_others'=>p('civil_status_others'),
        'citizenship'=>p('citizenship'),
        'dual_citizen'=>(int)p('dual_citizen'),
        'dual_citizen_type'=>p('dual_citizen_type')?:null,
        'dual_citizen_country'=>p('dual_citizen_country'),
        'height_m'=>p('height_m')?:null, 'weight_kg'=>p('weight_kg')?:null,
        'blood_type'=>p('blood_type'),
        'gsis_id'=>p('gsis_id'), 'pagibig_id'=>p('pagibig_id'),
        'philhealth_no'=>p('philhealth_no'), 'sss_no'=>p('sss_no'),
        'tin'=>p('tin'), 'agency_employee_no'=>p('agency_employee_no'),
        'res_house_no'=>p('res_house_no'), 'res_street'=>p('res_street'),
        'res_subdivision'=>p('res_subdivision'), 'res_barangay'=>p('res_barangay'),
        'res_city_municipality'=>p('res_city_municipality'), 'res_province'=>p('res_province'),
        'res_zip_code'=>p('res_zip_code'),
        'per_house_no'=>p('per_house_no'), 'per_street'=>p('per_street'),
        'per_subdivision'=>p('per_subdivision'), 'per_barangay'=>p('per_barangay'),
        'per_city_municipality'=>p('per_city_municipality'), 'per_province'=>p('per_province'),
        'per_zip_code'=>p('per_zip_code'),
        'telephone_no'=>p('telephone_no'), 'mobile_no'=>p('mobile_no'),
        'email'=>p('email'), 'work_schedule'=>p('work_schedule'),
        'is_active'=>(int)p('is_active',1), 'updated_by'=>$uid,
    ];

    if ($pds_id) {
        // Check duplicate employee_no
        $chk = $conn->prepare("SELECT id FROM pds_personal_info WHERE employee_no=? AND id<>?");
        $chk->execute([p('employee_no'), $pds_id]);
        if ($chk->fetch()) err('Employee No. already assigned to another record.');

        $sets = implode(', ', array_map(fn($k)=>"`$k`=:$k", array_keys($fields)));
        $stmt = $conn->prepare("UPDATE pds_personal_info SET $sets WHERE id=:id");
        $fields['id'] = $pds_id;
        $stmt->execute($fields);
        resetWorkflowToDraft($conn, $pds_id);
        updatePdsUserLink($pds_id, (string)p('employee_no'), (string)p('bio_id'));
        audit($conn, $pds_id, 'updated', 'Personal Information', $uid, $uname);
        ok(['pds_id' => $pds_id, 'workflow_status' => 'verified']);
    } else {
        $fields['created_by'] = $uid;
        $cols = implode(', ', array_map(fn($k)=>"`$k`", array_keys($fields)));
        $vals = implode(', ', array_map(fn($k)=>":$k", array_keys($fields)));
        $stmt = $conn->prepare("INSERT INTO pds_personal_info ($cols) VALUES ($vals)");
        $stmt->execute($fields);
        $new_id = (int)$conn->lastInsertId();
        resetWorkflowToDraft($conn, $new_id);
        updatePdsUserLink($new_id, (string)p('employee_no'), (string)p('bio_id'));
        audit($conn, $new_id, 'created', 'Personal Information', $uid, $uname);
        ok(['pds_id' => $new_id, 'workflow_status' => 'verified']);
    }

// ── SAVE OFFICE ───────────────────────────────────────────────────
case 'save_office':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $office_name = trim((string)p('office_name'));
    $division    = trim((string)p('division'));
    $chk = $conn->prepare("SELECT id FROM pds_office WHERE pds_id=?");
    $chk->execute([$pds_id]); $row = $chk->fetch();
    if ($row) {
        $conn->prepare("UPDATE pds_office SET office_name=?, division=? WHERE pds_id=?")
             ->execute([$office_name, $division, $pds_id]);
    } else {
        $conn->prepare("INSERT INTO pds_office (pds_id, office_name, division) VALUES (?, ?, ?)")
             ->execute([$pds_id, $office_name, $division]);
    }
    audit($conn, $pds_id, 'updated', 'Office', $uid, $uname);
    ok();

// ── SAVE FAMILY BACKGROUND ────────────────────────────────────────
case 'save_family':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $f = [
        'pds_id'=>$pds_id,
        'spouse_surname'=>p('spouse_surname'), 'spouse_first_name'=>p('spouse_first_name'),
        'spouse_middle_name'=>p('spouse_middle_name'), 'spouse_name_ext'=>p('spouse_name_ext'),
        'spouse_occupation'=>p('spouse_occupation'), 'spouse_employer'=>p('spouse_employer'),
        'spouse_business_address'=>p('spouse_business_address'), 'spouse_telephone'=>p('spouse_telephone'),
        'father_surname'=>p('father_surname'), 'father_first_name'=>p('father_first_name'),
        'father_middle_name'=>p('father_middle_name'), 'father_name_ext'=>p('father_name_ext'),
        'mother_maiden_name'=>p('mother_maiden_name'), 'mother_surname'=>p('mother_surname'),
        'mother_first_name'=>p('mother_first_name'), 'mother_middle_name'=>p('mother_middle_name'),
    ];
    $chk = $conn->prepare("SELECT id FROM pds_family_background WHERE pds_id=?");
    $chk->execute([$pds_id]); $row = $chk->fetch();
    if ($row) {
        $sets = implode(', ', array_map(fn($k)=>"`$k`=:$k", array_keys($f)));
        $conn->prepare("UPDATE pds_family_background SET $sets WHERE pds_id=:pds_id")->execute($f);
    } else {
        $conn->prepare("INSERT INTO pds_family_background (".implode(',',array_map(fn($k)=>"`$k`",array_keys($f))).") VALUES (".implode(',',array_map(fn($k)=>":$k",array_keys($f))).")")->execute($f);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Family Background', $uid, $uname);
    ok();

// ── SAVE CHILDREN (replace-all) ───────────────────────────────────
case 'save_children':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $rows = $body['children'] ?? [];
    $conn->prepare("DELETE FROM pds_children WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_children (pds_id, bio_id, surname, first_name, middle_name, name_ext, birth_date, sort_order)
        SELECT ?, bio_id, ?, ?, ?, ?, ?, ? FROM pds_personal_info WHERE id=?");
    foreach ($rows as $i => $r) {
        if (empty(trim($r['surname']??'')) && empty(trim($r['first_name']??''))) continue;
        $ins->execute([$pds_id, $r['surname']??'', $r['first_name']??'', $r['middle_name']??'',
                       $r['name_ext']??'', ($r['birth_date']??'')?:null, $i, $pds_id]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Children', $uid, $uname);
    ok();

// ── SAVE EDUCATION (replace-all) ─────────────────────────────────
case 'save_education':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $rows = $body['education'] ?? [];
    $conn->prepare("DELETE FROM pds_education WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_education (pds_id,bio_id,level,school_name,degree_course,period_from,period_to,highest_level_units,year_graduated,honors_received,sort_order)
        SELECT ?,bio_id,?,?,?,?,?,?,?,?,? FROM pds_personal_info WHERE id=?");
    foreach ($rows as $i => $r) {
        if (empty($r['level']??'') && empty($r['school_name']??'')) continue;
        $ins->execute([$pds_id,$r['level']??'',$r['school_name']??'',$r['degree_course']??'',
                       $r['period_from']??'',$r['period_to']??'',$r['highest_level_units']??'',
                       $r['year_graduated']??'',$r['honors_received']??'',$i,$pds_id]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Educational Background', $uid, $uname);
    ok();

// ── SAVE CIVIL SERVICE (replace-all) ─────────────────────────────
case 'save_civil':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $rows = $body['civil'] ?? [];
    $conn->prepare("DELETE FROM pds_civil_service WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_civil_service (pds_id,bio_id,career_service,rating,exam_date,exam_place,license_type,license_no,license_validity,sort_order)
        SELECT ?,bio_id,?,?,?,?,?,?,?,? FROM pds_personal_info WHERE id=?");
    foreach ($rows as $i => $r) {
        if (empty($r['career_service']??'')) continue;
        $ins->execute([$pds_id,$r['career_service']??'',$r['rating']??'',$r['exam_date']??'',
                       $r['exam_place']??'',$r['license_type']??'',$r['license_no']??'',$r['license_validity']??'',$i,$pds_id]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Civil Service Eligibility', $uid, $uname);
    ok();

// ── SAVE WORK EXPERIENCE (replace-all) ───────────────────────────
case 'save_work':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $rows = $body['work'] ?? [];
    $conn->prepare("DELETE FROM pds_work_experience WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_work_experience
        (pds_id,bio_id,date_from,date_to,position_title,department_agency,division,section,remarks,monthly_salary,salary_grade_step,salary_grade,step_increment,salary_type,appointment_status,gov_service,sort_order)
        SELECT ?,bio_id,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? FROM pds_personal_info WHERE id=?");
    foreach ($rows as $i => $r) {
        if (empty($r['position_title']??'')) continue;
        $ins->execute([$pds_id,$r['date_from']??'',$r['date_to']??'',$r['position_title'],
                       $r['department_agency']??'',$r['division']??'',$r['section']??'',
                       $r['remarks']??'',$r['monthly_salary']??null,$r['salary_grade_step']??'',
                       $r['salary_grade']??'',$r['step_increment']??'',$r['salary_type']??null,
                       $r['appointment_status']??'',$r['gov_service']??null,$i,$pds_id]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Work Experience', $uid, $uname);
    ok();

// ── SAVE VOLUNTARY WORK (replace-all) ────────────────────────────
case 'save_voluntary':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $rows = $body['voluntary'] ?? [];
    $conn->prepare("DELETE FROM pds_voluntary_work WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_voluntary_work (pds_id,bio_id,organization,date_from,date_to,no_of_hours,position_nature,sort_order)
        SELECT ?,bio_id,?,?,?,?,?,? FROM pds_personal_info WHERE id=?");
    foreach ($rows as $i => $r) {
        if (empty($r['organization']??'')) continue;
        $ins->execute([$pds_id,$r['organization'],$r['date_from']??'',$r['date_to']??'',
                       $r['no_of_hours']??null,$r['position_nature']??'',$i,$pds_id]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Voluntary Work', $uid, $uname);
    ok();

// ── SAVE L&D (replace-all) ────────────────────────────────────────
case 'save_ld':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $rows = $body['ld'] ?? [];
    $conn->prepare("DELETE FROM pds_learning_development WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_learning_development (pds_id,bio_id,title,date_from,date_to,no_of_hours,ld_type,conducted_by,sort_order)
        SELECT ?,bio_id,?,?,?,?,?,?,? FROM pds_personal_info WHERE id=?");
    foreach ($rows as $i => $r) {
        if (empty($r['title']??'')) continue;
        $ins->execute([$pds_id,$r['title'],$r['date_from']??'',$r['date_to']??'',
                       $r['no_of_hours']??null,$r['ld_type']??null,$r['conducted_by']??'',$i,$pds_id]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Learning & Development', $uid, $uname);
    ok();

// ── SAVE OTHER INFO (replace-all) ────────────────────────────────
case 'save_other':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $conn->prepare("DELETE FROM pds_other_info WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_other_info (pds_id,bio_id,info_type,detail,sort_order)
        SELECT ?,bio_id,?,?,? FROM pds_personal_info WHERE id=?");
    foreach (['Skills','Recognition','Membership'] as $type) {
        $rows = $body[strtolower($type)] ?? [];
        foreach ($rows as $i => $r) {
            $val = trim($r['detail'] ?? $r ?? '');
            if (!$val) continue;
            $ins->execute([$pds_id, $type, $val, $i, $pds_id]);
        }
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Other Information', $uid, $uname);
    ok();

// ── SAVE QUESTIONNAIRE ────────────────────────────────────────────
case 'save_questions':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $f = [
        'pds_id'=>$pds_id,
        'q34a_related_3rd'=>p('q34a_related_3rd')?:null, 'q34a_details'=>p('q34a_details'),
        'q34b_related_4th'=>p('q34b_related_4th')?:null, 'q34b_details'=>p('q34b_details'),
        'q35a_admin_offense'=>p('q35a_admin_offense')?:null, 'q35a_details'=>p('q35a_details'),
        'q35b_criminally_charged'=>p('q35b_criminally_charged')?:null,
        'q35b_charge_date'=>p('q35b_charge_date'), 'q35b_case_status'=>p('q35b_case_status'),
        'q36_convicted'=>p('q36_convicted')?:null, 'q36_details'=>p('q36_details'),
        'q37_separated'=>p('q37_separated')?:null, 'q37_details'=>p('q37_details'),
        'q38a_election_candidate'=>p('q38a_election_candidate')?:null, 'q38a_details'=>p('q38a_details'),
        'q38b_resigned_campaign'=>p('q38b_resigned_campaign')?:null, 'q38b_details'=>p('q38b_details'),
        'q39_immigrant'=>p('q39_immigrant')?:null, 'q39_details'=>p('q39_details'),
        'q40a_pwd'=>p('q40a_pwd')?:null, 'q40a_details'=>p('q40a_details'),
        'q40b_solo_parent'=>p('q40b_solo_parent')?:null, 'q40b_details'=>p('q40b_details'),
        'q40c_indigenous'=>p('q40c_indigenous')?:null, 'q40c_details'=>p('q40c_details'),
    ];
    $chk = $conn->prepare("SELECT id FROM pds_questionnaire WHERE pds_id=?");
    $chk->execute([$pds_id]); $row = $chk->fetch();
    if ($row) {
        $sets = implode(', ', array_map(fn($k)=>"`$k`=:$k", array_keys($f)));
        $conn->prepare("UPDATE pds_questionnaire SET $sets WHERE pds_id=:pds_id")->execute($f);
    } else {
        $conn->prepare("INSERT INTO pds_questionnaire (".implode(',',array_map(fn($k)=>"`$k`",array_keys($f))).") VALUES (".implode(',',array_map(fn($k)=>":$k",array_keys($f))).")")->execute($f);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Questionnaire', $uid, $uname);
    ok();

// ── SAVE REFERENCES (replace-all) ────────────────────────────────
case 'save_references':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $rows = $body['references'] ?? [];
    $conn->prepare("DELETE FROM pds_references WHERE pds_id=?")->execute([$pds_id]);
    $ins = $conn->prepare("INSERT INTO pds_references (pds_id,bio_id,surname,first_name,middle_name,address,telephone,sort_order)
        SELECT ?,bio_id,?,?,?,?,?,? FROM pds_personal_info WHERE id=?");
    foreach ($rows as $i => $r) {
        if (empty($r['surname']??'')) continue;
        $ins->execute([$pds_id,$r['surname'],$r['first_name']??'',$r['middle_name']??'',$r['address']??'',$r['telephone']??'',$i,$pds_id]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'References', $uid, $uname);
    ok();

// ── SAVE GOVERNMENT ID ────────────────────────────────────────────
case 'save_govid':
    $pds_id = pint('pds_id'); if (!$pds_id) err('pds_id required');
    enforcePdsOwnership($conn, $pds_id);
    $f = ['pds_id'=>$pds_id,'gov_id_type'=>p('gov_id_type'),'gov_id_no'=>p('gov_id_no'),'issuance_date_place'=>p('issuance_date_place')];
    $chk = $conn->prepare("SELECT id FROM pds_government_id WHERE pds_id=?");
    $chk->execute([$pds_id]); $row = $chk->fetch();
    if ($row) {
        $conn->prepare("UPDATE pds_government_id SET gov_id_type=:gov_id_type,gov_id_no=:gov_id_no,issuance_date_place=:issuance_date_place WHERE pds_id=:pds_id")->execute($f);
    } else {
        $conn->prepare("INSERT INTO pds_government_id (pds_id,bio_id,gov_id_type,gov_id_no,issuance_date_place)
            SELECT :pds_id,bio_id,:gov_id_type,:gov_id_no,:issuance_date_place FROM pds_personal_info WHERE id=:pds_id2")
            ->execute(['pds_id'=>$pds_id,'pds_id2'=>$pds_id,'gov_id_type'=>p('gov_id_type'),'gov_id_no'=>p('gov_id_no'),'issuance_date_place'=>p('issuance_date_place')]);
    }
    resetWorkflowToDraft($conn, $pds_id);
    audit($conn, $pds_id, 'updated', 'Government ID', $uid, $uname);
    ok();

case 'delete':
    requireAdminOnly();
    $pds_id = (int)($_GET['pds_id'] ?? pint('pds_id'));
    if (!$pds_id) err('pds_id required');
    $conn->prepare("UPDATE pds_personal_info SET is_active=0, updated_by=? WHERE id=?")->execute([$uid, $pds_id]);
    audit($conn, $pds_id, 'deleted', 'Personal Information', $uid, $uname);
    ok();

default:
    err("Unknown action: $action");
}
