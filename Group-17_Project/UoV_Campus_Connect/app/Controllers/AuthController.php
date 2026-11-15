<?php
namespace App\Controllers;
class AuthController {
    public function loginForm(): void { if (user()) go('dashboard');render('login',['pageTitle'=>'Sign in']); }
    public function registerForm(): void { if (user()) go('dashboard');render('register',['pageTitle'=>'Create account']); }
    public function register(): void {
        if (user()) go('dashboard');
        $name=post_str('full_name');$email=strtolower(post_str('email'));$number=post_str('student_number');$faculty=post_str('faculty');$password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');
        old_store(['full_name'=>$name,'email'=>$email,'student_number'=>$number,'faculty'=>$faculty]);
        if (text_length($name)<3||text_length($name)>120||!filter_var($email,FILTER_VALIDATE_EMAIL)||text_length($email)>190) { flash('danger','Enter a valid name and email address.');go('register'); }
        if ($number===''||text_length($number)>50||!in_array($faculty,array_slice(faculty_options(),0,3),true)) { flash('danger','Enter your student number and select your faculty.');go('register'); }
        if (strlen($password)<10 || strlen($password)>72 || $password!==$confirm) { flash('danger','Passwords must match and contain 10–72 characters.');go('register'); }
        if (row('SELECT id FROM users WHERE email=? OR student_number=?',[$email,$number])) { flash('danger','Email or student number is already registered.');go('register'); }
        try {
            execute("INSERT INTO users(full_name,email,student_number,faculty,password_hash,role) VALUES(?,?,?,?,?,'student')",[$name,$email,$number,$faculty,password_hash($password,PASSWORD_DEFAULT)]);
        } catch (\PDOException $e) { flash('danger','Unable to register. This email or student number may already exist.');go('register'); }
        $id=(int)db()->lastInsertId();
        session_regenerate_id(true); $_SESSION['uid']=$id;unset($_SESSION['old']);
        audit('register','user',$id);flash('success','Welcome! Your student account has been created.');go('dashboard');
    }
    public function login(): void {
        if (user()) go('dashboard');
        $email=strtolower(post_str('email'));$password=(string)($_POST['password']??'');
        if (text_length($email)>190||strlen($password)>1024) { flash('danger','Invalid email or password.');go('login'); }
        $account=\App\Models\User::byEmail($email);
        if (!$account || !password_verify($password,$account['password_hash']) || $account['status']!=='active') { flash('danger','Invalid email or password, or account unavailable.');go('login'); }
        session_regenerate_id(true); $_SESSION['uid']=(int)$account['id'];
        flash('success','You are signed in.');go('dashboard');
    }
    public function logout(): void {
        $_SESSION=[];session_regenerate_id(true);session_destroy();
        header('Location: '.url('home'),true,303);exit;
    }
    public function profileSave(): void {
        $me=require_login();$name=post_str('full_name');$email=strtolower(post_str('email'));
        $faculty=post_str('faculty');
        if(text_length($name)<3||text_length($name)>120||!filter_var($email,FILTER_VALIDATE_EMAIL)||
            text_length($email)>190||($faculty!==''&&!in_array($faculty,faculty_options(),true))){
            flash('danger','Please check your name, email, and faculty.');go('profile');
        }
        if(row('SELECT id FROM users WHERE email=? AND id<>?',[$email,$me['id']])){
            flash('danger','That email address is already registered.');go('profile');
        }
        execute('UPDATE users SET full_name=?,email=?,faculty=? WHERE id=?',[$name,$email,$faculty?:null,$me['id']]);
        $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');
        if($current!==''||$new!==''){
            $hash=row('SELECT password_hash FROM users WHERE id=?',[$me['id']])['password_hash'];
            if(!password_verify($current,$hash)||strlen($new)<10||strlen($new)>72){
                flash('warning','Profile saved; password unchanged. Check current password and new password length.');go('profile');
            }
            execute('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$me['id']]);
            session_regenerate_id(true);
        }
        audit('profile_updated','user',$me['id']);flash('success','Profile saved successfully.');go('profile');
    }
    public function markRead(): void {
        $me=require_login();$id=valid_int($_POST['id']??null);
        if ($id) execute('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?',[$id,$me['id']]);
        else execute('UPDATE notifications SET is_read=1 WHERE user_id=?',[$me['id']]);
        go('notifications');
    }
}
