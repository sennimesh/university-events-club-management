<?php
namespace App\Controllers;

final class SiteController {
    public function home(): void {
        $featuredEvents = \App\Models\Event::featured();
        $featuredClubs = \App\Models\Club::featured();
        $announcements = rows("SELECT a.*,c.name AS club_name FROM announcements a JOIN clubs c ON c.id=a.club_id WHERE c.status='approved' AND a.status='published' ORDER BY a.created_at DESC LIMIT 4");
        $stats = ['clubs'=>scalar("SELECT COUNT(*) FROM clubs WHERE status='approved'"),'events'=>scalar("SELECT COUNT(*) FROM events WHERE status='approved' AND starts_at>=NOW()"),'members'=>scalar("SELECT COUNT(DISTINCT user_id) FROM club_memberships WHERE status='approved'")];
        render('home', compact('featuredEvents','featuredClubs','announcements','stats')+['pageTitle'=>'Discover campus life']);
    }
    public function clubs(): void {
        $search = text_slice(trim((string)($_GET['q'] ?? '')),0,80);
        $category = trim((string)($_GET['category'] ?? ''));
        $faculty = trim((string)($_GET['faculty'] ?? ''));
        $where = ["c.status='approved'"];
        $args = [];
        if ($search !== '') { $where[] = "(c.name LIKE ? OR c.description LIKE ?)"; $term='%'.clean_like($search).'%'; $args[]=$term; $args[]=$term; }
        if ($category !== '') { $where[]='c.category=?'; $args[]=$category; }
        if ($faculty !== '') { $where[]='c.faculty=?'; $args[]=$faculty; }
        $clubs = rows("SELECT c.*, (SELECT COUNT(*) FROM club_memberships m WHERE m.club_id=c.id AND m.status='approved') AS members, (SELECT COUNT(*) FROM events e WHERE e.club_id=c.id AND e.status='approved' AND e.starts_at>=NOW()) AS events_count FROM clubs c WHERE ".implode(' AND ',$where).' ORDER BY c.name', $args);
        $categories = rows("SELECT DISTINCT category FROM clubs WHERE status='approved' ORDER BY category");
        render('clubs', compact('clubs','search','category','faculty','categories')+['pageTitle'=>'Clubs & societies']);
    }
    public function club(): void {
        $id=valid_int($_GET['id'] ?? null);
        $club=row("SELECT c.*,u.full_name AS leader_name,(SELECT COUNT(*) FROM club_memberships m WHERE m.club_id=c.id AND m.status='approved') AS members FROM clubs c LEFT JOIN users u ON u.id=c.leader_user_id WHERE c.id=? AND c.status='approved'",[$id]);
        if (!$club) { $this->notFound(); return; }
        $events=rows("SELECT e.*,(SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.status='registered') AS booked FROM events e WHERE e.club_id=? AND e.status IN ('approved','completed') ORDER BY e.starts_at DESC LIMIT 8",[$id]);
        $notices=rows("SELECT a.*,u.full_name AS author FROM announcements a JOIN users u ON u.id=a.posted_by WHERE club_id=? AND a.status='published' ORDER BY a.created_at DESC LIMIT 8",[$id]);
        $membership=user() ? row('SELECT status FROM club_memberships WHERE club_id=? AND user_id=?',[$id,user()['id']]) : null;
        render('club',compact('club','events','notices','membership')+['pageTitle'=>$club['name']]);
    }
    public function events(): void {
        $search=text_slice(trim((string)($_GET['q'] ?? '')),0,80);
        $clubId=valid_int($_GET['club'] ?? null);
        $when=(string)($_GET['when'] ?? 'upcoming');
        $categoryId=valid_int($_GET['category_id']??null);
        $where=["e.status IN ('approved','completed')","c.status='approved'"];$args=[];
        if($categoryId){$where[]='e.category_id=?';$args[]=$categoryId;}
        if ($search!=='') { $where[]='(e.title LIKE ? OR e.description LIKE ? OR e.venue LIKE ?)';$term='%'.clean_like($search).'%';$args=array_merge($args,[$term,$term,$term]); }
        if ($clubId) { $where[]='e.club_id=?';$args[]=$clubId; }
        if ($when!=='all') $where[]=($when==='past' ? 'e.ends_at < NOW()' : 'e.ends_at >= NOW()');
        $events=rows("SELECT e.*,c.name AS club_name,c.category AS club_category,(SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.status='registered') AS booked FROM events e JOIN clubs c ON c.id=e.club_id WHERE ".implode(' AND ',$where).' ORDER BY e.starts_at '.($when==='past'?'DESC':'ASC').' LIMIT 100',$args);
        $clubs=rows("SELECT id,name FROM clubs WHERE status='approved' ORDER BY name");
        $categories=rows('SELECT id,name FROM event_categories ORDER BY name');
        render('events',compact('events','search','clubId','when','clubs','categories','categoryId')+['pageTitle'=>'Campus events']);
    }
    public function event(): void {
        $id=valid_int($_GET['id'] ?? null);
        $event=row("SELECT e.*,c.name AS club_name,c.category AS club_category,c.status AS club_status,c.leader_user_id,u.full_name AS organizer,(SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.status='registered') AS booked FROM events e JOIN clubs c ON c.id=e.club_id JOIN users u ON u.id=e.created_by WHERE e.id=?",[$id]);
        $viewer=user();
        $registration=$viewer ? row('SELECT r.status,r.attended_at,a.is_present FROM event_registrations r LEFT JOIN attendance a ON a.registration_id=r.id WHERE r.user_id=? AND r.event_id=?',[$viewer['id'],$id]) : null;
        $canManage=$viewer && ($viewer['role']==='admin' || ($viewer['role']==='club_leader' && (int)($event['leader_user_id']??0)===(int)$viewer['id']));
        $public=$event && in_array($event['status'],['approved','completed'],true) && $event['club_status']==='approved';
        if (!$event || (!$public && !$canManage && !$registration)) { $this->notFound(); return; }
        $myFeedback=$viewer ? row('SELECT id,rating,comment FROM event_feedback WHERE user_id=? AND event_id=?',[user()['id'],$id]) : null;
        $feedback=rows('SELECT f.rating,f.comment,f.created_at,u.full_name FROM event_feedback f JOIN users u ON u.id=f.user_id WHERE f.event_id=? ORDER BY f.created_at DESC LIMIT 15',[$id]);
        render('event',compact('event','registration','feedback','myFeedback')+['pageTitle'=>$event['title']]);
    }
    public function calendar(): void {
        $ym=(string)($_GET['month']??date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$ym)) $ym=date('Y-m');
        $first=strtotime($ym.'-01');
        if ($first===false || (int)date('Y',$first)<2020 || (int)date('Y',$first)>2100) { $ym=date('Y-m'); $first=strtotime($ym.'-01'); }
        $start=date('Y-m-01 00:00:00',$first);
        $end=date('Y-m-01 00:00:00',strtotime('+1 month',$first));
        $events=rows("SELECT e.id,e.title,e.starts_at,c.name AS club_name FROM events e JOIN clubs c ON c.id=e.club_id WHERE e.status='approved' AND c.status='approved' AND e.starts_at>=? AND e.starts_at<? ORDER BY e.starts_at",[$start,$end]);
        $byDay=[];foreach($events as $event) $byDay[(int)date('j',strtotime($event['starts_at']))][]=$event;
        render('calendar',compact('ym','first','byDay')+['pageTitle'=>'Event calendar']);
    }
    public function dashboard(): void {
        $me=require_login();
        $memberships=rows("SELECT m.status,m.joined_at,c.id,c.name,c.category FROM club_memberships m JOIN clubs c ON c.id=m.club_id WHERE m.user_id=? ORDER BY m.joined_at DESC",[$me['id']]);
        $registrations=rows("SELECT r.status,r.attended_at,e.id,e.title,e.starts_at,e.venue,e.status AS event_status,c.name AS club_name FROM event_registrations r JOIN events e ON e.id=r.event_id JOIN clubs c ON c.id=e.club_id WHERE r.user_id=? ORDER BY e.starts_at DESC",[$me['id']]);
        $notifications=rows('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5',[$me['id']]);
        render('dashboard',compact('me','memberships','registrations','notifications')+['pageTitle'=>'My dashboard']);
    }
    public function notifications(): void { $me=require_login();$items=rows('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100',[$me['id']]);render('notifications',compact('items')+['pageTitle'=>'Notifications']); }
    public function profile(): void { $me=require_login();render('profile',compact('me')+['pageTitle'=>'Account settings']); }
    public function about(): void { render('about',['pageTitle'=>'About this project']); }
    private function notFound(): void { http_response_code(404);render('error',['pageTitle'=>'Not found','errorMessage'=>'This club or event is not available.']); }
}
