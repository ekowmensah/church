<?php
// Keep dimension options inside the same church scope as the parent report.
$organizationWhere = !empty($is_super_admin) ? '' : ' WHERE church_id = ' . max(0, (int) ($actorChurchId ?? 0));
$orgs = $conn->query("SELECT id, name FROM organizations{$organizationWhere} ORDER BY name ASC");
?>
<select name="org_member" class="form-control">
  <option value="">All</option>
  <?php if ($orgs) while($o = $orgs->fetch_assoc()): ?>
    <option value="<?= $o['id'] ?>"<?= isset($_GET['org_member']) && $_GET['org_member']==$o['id'] ? ' selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
  <?php endwhile; ?>
</select>
