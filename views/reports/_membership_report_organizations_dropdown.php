<?php
// Fetch all organizations for dropdown
$orgs = $conn->query("SELECT id, name FROM organizations ORDER BY name ASC");
?>
<select name="org_member" class="form-control">
  <option value="">All</option>
  <?php if ($orgs) while($o = $orgs->fetch_assoc()): ?>
    <option value="<?= $o['id'] ?>"<?= isset($_GET['org_member']) && $_GET['org_member']==$o['id'] ? ' selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
  <?php endwhile; ?>
</select>
