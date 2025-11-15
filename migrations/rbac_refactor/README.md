# RBAC Refactoring Migrations

This directory contains database migrations for the comprehensive RBAC (Role-Based Access Control) system refactoring.

## Migration Structure

```
rbac_refactor/
├── 000_create_migration_tracker.sql    # Migration tracking system
├── 001_create_permission_categories.sql # Permission categories
├── 002_enhance_permissions_table.sql    # Enhanced permissions
├── 003_add_role_hierarchy.sql          # Role hierarchy support
├── 004_enhance_relationships.sql       # Enhanced relationships
├── 005_create_audit_system.sql         # Comprehensive audit
├── 006_create_role_templates.sql       # Role templates
├── run_migrations.php                  # Migration runner
├── rollbacks/                          # Rollback scripts
│   ├── 001_rollback_*.sql
│   ├── 002_rollback_*.sql
│   └── ...
└── README.md                           # This file
```

## Running Migrations

### Via Command Line (Recommended)
```bash
# Show migration status
php run_migrations.php status

# Dry run (test without executing)
php run_migrations.php run --dry-run

# Execute all pending migrations
php run_migrations.php run

# Rollback last migration
php run_migrations.php rollback
```

### Via Web Interface
Navigate to: `http://localhost/church/migrations/rbac_refactor/run_migrations.php`

**Note:** Only super admin (user_id = 3) can access the web interface.

## Migration Order

Migrations are executed in numerical order:

1. **000** - Migration Tracker (automatic)
2. **001** - Permission Categories
3. **002** - Enhanced Permissions Table
4. **003** - Role Hierarchy
5. **004** - Enhanced Relationships
6. **005** - Comprehensive Audit System
7. **006** - Role Templates

## Before Running Migrations

1. **Backup your database:**
   ```bash
   mysqldump -u root -p fmckgmib_portal > backup_before_rbac_$(date +%Y%m%d).sql
   ```

2. **Test on development environment first**

3. **Review each migration file**

4. **Ensure no active users during migration**

## Rollback Procedure

If something goes wrong:

```bash
# Rollback the last migration
php run_migrations.php rollback

# Check status
php run_migrations.php status
```

## Migration Status

Each migration can have the following statuses:
- `pending` - Not yet executed
- `running` - Currently executing
- `completed` - Successfully executed
- `failed` - Execution failed (check error_message)
- `rolled_back` - Has been rolled back

## Troubleshooting

### Migration Failed
1. Check the `rbac_migrations` table for error messages
2. Review the SQL file for syntax errors
3. Check database permissions
4. Ensure foreign key constraints are satisfied

### Rollback Failed
1. Check if rollback file exists in `rollbacks/` directory
2. Manually review and execute rollback SQL if needed
3. Update migration status manually if necessary

### Manual Status Update
```sql
-- Mark migration as completed
UPDATE rbac_migrations 
SET status = 'completed' 
WHERE migration_number = '001';

-- Mark migration as rolled back
UPDATE rbac_migrations 
SET status = 'rolled_back' 
WHERE migration_number = '001';
```

## Adding New Migrations

1. Create migration file: `00X_migration_name.sql`
2. Create rollback file: `rollbacks/00X_rollback_migration_name.sql`
3. Test on development environment
4. Run migration

## Support

For issues or questions:
- Check migration logs in `rbac_migrations` table
- Review error messages
- Contact system administrator

---

**Last Updated:** 2025-11-15  
**Version:** 1.0.0
