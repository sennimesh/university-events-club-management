<section class="page-banner"><div class="container"><div class="section-label">ADMINISTRATION</div><h1>Add administrator</h1><p>Create a new admin account with its own login details.</p></div></section>
<section class="section container narrow"><?php require dirname(__DIR__).'/partials/admin_nav.php'; ?>
<div class="card panel"><form method="post" action="<?= e(url('admin/save')) ?>" class="form-stack"><?= csrf_field() ?>
<label>Full name<input name="full_name" minlength="3" maxlength="150" required></label>
<label>Email<input type="email" name="email" maxlength="190" required></label>
<label>Student number (optional)<input name="student_number" maxlength="50"></label>
<label>Faculty<select name="faculty" required><?php foreach(faculty_options() as $faculty): ?><option value="<?= e($faculty) ?>"><?= e($faculty) ?></option><?php endforeach; ?></select></label>
<label>Password for new administrator<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
<p class="small-muted">To promote an existing student, use Users → Change role → Administrator instead of creating another account.</p>
<button type="submit" class="btn btn-primary">Create administrator</button></form></div></section>
