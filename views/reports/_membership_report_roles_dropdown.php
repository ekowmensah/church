<?php
// Fetch all roles of service for dropdown
$roles = $conn->query("SELECT id, name FROM roles_of_serving ORDER BY name ASC");
?>
<select name="role_of_service" class="form-control">
  <option value="">All</option>
  <?php if ($roles) while($r = $roles->fetch_assoc()): ?>
    <option value="<?= $r['id'] ?>"<?= isset($_GET['role_of_service']) && $_GET['role_of_service']==$r['id'] ? ' selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
  <?php endwhile; ?>
</select>
