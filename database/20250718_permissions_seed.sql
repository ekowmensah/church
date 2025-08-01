-- Seed canonical permissions into permissions table
INSERT IGNORE INTO permissions (name, `group`, description)
VALUES
('view_dashboard', 'Dashboard', 'View the main dashboard'),
('view_member', 'Members', 'View member records'),
('create_member', 'Members', 'Create new member records'),
('edit_member', 'Members', 'Edit member records'),
('delete_member', 'Members', 'Delete member records'),
('export_member', 'Members', 'Export member data'),
('import_member', 'Members', 'Import member data'),
('upload_member', 'Members', 'Upload member files'),
('activate_member', 'Members', 'Activate a member'),
('deactivate_member', 'Members', 'Deactivate a member'),
('permanently_delete_member', 'Members', 'Permanently delete a member'),
('restore_deleted_member', 'Members', 'Restore a deleted member'),
('view_member_profile', 'Members', 'View member profile'),
('edit_member_profile', 'Members', 'Edit member profile'),
('view_member_organizations', 'Members', 'View member organizations'),
('edit_member_organizations', 'Members', 'Edit member organizations'),
('view_member_health_records', 'Members', 'View member health records'),
('view_member_events', 'Members', 'View member events'),
('view_member_feedback', 'Members', 'View member feedback'),
('respond_member_feedback', 'Members', 'Respond to member feedback'),
('convert_visitor_to_member', 'Members', 'Convert a visitor to a member'),
('view_attendance_list', 'Attendance', 'View attendance list'),
('view_attendance_history', 'Attendance', 'View attendance history'),
('mark_attendance', 'Attendance', 'Mark attendance'),
('edit_attendance', 'Attendance', 'Edit attendance'),
('delete_attendance', 'Attendance', 'Delete attendance'),
('export_attendance', 'Attendance', 'Export attendance records'),
('import_attendance', 'Attendance', 'Import attendance records'),
('view_attendance_report', 'Attendance', 'View attendance report'),
('export_attendance_report', 'Attendance', 'Export attendance report'),
('view_payment_list', 'Payments', 'View payment list'),
('view_payment_history', 'Payments', 'View payment history'),
('make_payment', 'Payments', 'Make a payment'),
('create_payment', 'Payments', 'Create a payment'),
('edit_payment', 'Payments', 'Edit a payment'),
('delete_payment', 'Payments', 'Delete a payment'),
('reverse_payment', 'Payments', 'Reverse a payment'),
('view_payment_reversal_log', 'Payments', 'View payment reversal log'),
('export_payment', 'Payments', 'Export payment data'),
('import_payment', 'Payments', 'Import payment data'),
('view_payment_bulk', 'Payments', 'View bulk payment UI'),
('submit_bulk_payment', 'Payments', 'Submit bulk payment'),
('view_payment_bulk_summary', 'Payments', 'View bulk payment summary'),
('view_payment_types_today', 'Payments', 'View payment types for today'),
('view_payment_total_today', 'Payments', 'View total payments for today'),
('resend_payment_sms', 'Payments', 'Resend payment SMS'),
('view_paystack_callback', 'Payments', 'View Paystack callback'),
('view_hubtel_callback', 'Payments', 'View Hubtel callback'),
('view_reports_dashboard', 'Reports', 'View reports dashboard'),
('view_audit_report', 'Reports', 'View audit report'),
('view_event_report', 'Reports', 'View event report'),
('view_feedback_report', 'Reports', 'View feedback report'),
('view_health_report', 'Reports', 'View health report'),
('view_sms_report', 'Reports', 'View SMS report'),
('view_visitor_report', 'Reports', 'View visitor report'),
('view_membership_report', 'Reports', 'View membership report'),
('view_payment_report', 'Reports', 'View payment report'),
('export_payment_report', 'Reports', 'Export payment report'),
('export_health_report', 'Reports', 'Export health report'),
('export_feedback_report', 'Reports', 'Export feedback report'),
('export_membership_report', 'Reports', 'Export membership report'),
('export_visitor_report', 'Reports', 'Export visitor report'),
('view_accumulated_payment_type_report', 'Reports', 'View accumulated payment type report'),
('view_age_bracket_payment_report', 'Reports', 'View age bracket payment report'),
('view_age_bracket_report', 'Reports', 'View age bracket report'),
('view_baptism_report', 'Reports', 'View baptism report'),
('view_bibleclass_payment_report', 'Reports', 'View bibleclass payment report'),
('view_class_health_report', 'Reports', 'View class health report'),
('view_confirmation_report', 'Reports', 'View confirmation report'),
('view_date_of_birth_report', 'Reports', 'View date of birth report'),
('view_day_born_payment_report', 'Reports', 'View day born payment report'),
('view_employment_status_report', 'Reports', 'View employment status report'),
('view_gender_report', 'Reports', 'View gender report'),
('view_health_type_report', 'Reports', 'View health type report'),
('view_individual_health_report', 'Reports', 'View individual health report'),
('view_individual_payment_report', 'Reports', 'View individual payment report'),
('view_marital_status_report', 'Reports', 'View marital status report'),
('view_membership_status_report', 'Reports', 'View membership status report'),
('view_organisation_payment_report', 'Reports', 'View organisation payment report'),
('view_organisational_health_report', 'Reports', 'View organisational health report'),
('view_organisational_member_report', 'Reports', 'View organisational member report'),
('view_payment_made_report', 'Reports', 'View payment made report'),
('view_profession_report', 'Reports', 'View profession report'),
('view_registered_by_date_report', 'Reports', 'View registered by date report'),
('view_role_of_service_report', 'Reports', 'View role of service report'),
('view_zero_payment_type_report', 'Reports', 'View zero payment type report'),
('view_bibleclass_list', 'Bible Classes', 'View bibleclass list'),
('create_bibleclass', 'Bible Classes', 'Create bibleclass'),
('edit_bibleclass', 'Bible Classes', 'Edit bibleclass'),
('delete_bibleclass', 'Bible Classes', 'Delete bibleclass'),
('assign_bibleclass_leader', 'Bible Classes', 'Assign bibleclass leader'),
('remove_bibleclass_leader', 'Bible Classes', 'Remove bibleclass leader'),
('upload_bibleclass', 'Bible Classes', 'Upload bibleclass'),
('export_bibleclass', 'Bible Classes', 'Export bibleclass'),
('view_classgroup_list', 'Class Groups', 'View classgroup list'),
('create_classgroup', 'Class Groups', 'Create classgroup'),
('edit_classgroup', 'Class Groups', 'Edit classgroup'),
('delete_classgroup', 'Class Groups', 'Delete classgroup'),
('view_organization_list', 'Organizations', 'View organization list'),
('create_organization', 'Organizations', 'Create organization'),
('edit_organization', 'Organizations', 'Edit organization'),
('delete_organization', 'Organizations', 'Delete organization'),
('upload_organization', 'Organizations', 'Upload organization'),
('export_organization', 'Organizations', 'Export organization'),
('view_event_list', 'Events', 'View event list'),
('create_event', 'Events', 'Create event'),
('edit_event', 'Events', 'Edit event'),
('delete_event', 'Events', 'Delete event'),
('register_event', 'Events', 'Register for event'),
('view_event_registration_list', 'Events', 'View event registration list'),
('view_event_registration', 'Events', 'View event registration'),
('export_event', 'Events', 'Export event'),
('view_feedback_list', 'Feedback', 'View feedback list'),
('create_feedback', 'Feedback', 'Create feedback'),
('edit_feedback', 'Feedback', 'Edit feedback'),
('delete_feedback', 'Feedback', 'Delete feedback'),
('respond_feedback', 'Feedback', 'Respond to feedback'),
('view_memberfeedback_list', 'Feedback', 'View member feedback list'),
('view_memberfeedback_thread', 'Feedback', 'View member feedback thread'),
('view_memberfeedback_my', 'Feedback', 'View my member feedback'),
('view_health_list', 'Health', 'View health list'),
('create_health_record', 'Health', 'Create health record'),
('edit_health_record', 'Health', 'Edit health record'),
('delete_health_record', 'Health', 'Delete health record'),
('export_health', 'Health', 'Export health records'),
('import_health', 'Health', 'Import health records'),
('view_health_records', 'Health', 'View health records'),
('view_health_form_prefill', 'Health', 'View health form prefill'),
('view_health_bp_graph', 'Health', 'View health BP graph'),
('view_sms_log', 'SMS', 'View SMS log'),
('send_sms', 'SMS', 'Send SMS'),
('resend_sms', 'SMS', 'Resend SMS'),
('view_sms_logs', 'SMS', 'View SMS logs'),
('export_sms_logs', 'SMS', 'Export SMS logs'),
('manage_sms_templates', 'SMS', 'Manage SMS templates'),
('view_sms_settings', 'SMS', 'View SMS settings'),
('send_bulk_sms', 'SMS', 'Send bulk SMS'),
('send_member_message', 'SMS', 'Send member message'),
('view_visitor_sms_modal', 'SMS', 'View visitor SMS modal'),
('view_visitor_send_sms', 'SMS', 'View visitor send SMS'),
('view_sms_bulk', 'SMS', 'View SMS bulk'),
('view_visitor_list', 'Visitors', 'View visitor list'),
('create_visitor', 'Visitors', 'Create visitor'),
('edit_visitor', 'Visitors', 'Edit visitor'),
('delete_visitor', 'Visitors', 'Delete visitor'),
('convert_visitor', 'Visitors', 'Convert visitor'),
('send_visitor_sms', 'Visitors', 'Send visitor SMS'),
('export_visitor', 'Visitors', 'Export visitor'),
('view_sundayschool_list', 'Sunday School', 'View Sunday School list'),
('create_sundayschool', 'Sunday School', 'Create Sunday School'),
('edit_sundayschool', 'Sunday School', 'Edit Sunday School'),
('delete_sundayschool', 'Sunday School', 'Delete Sunday School'),
('transfer_sundayschool', 'Sunday School', 'Transfer Sunday School'),
('view_sundayschool_view', 'Sunday School', 'View Sunday School'),
('export_sundayschool', 'Sunday School', 'Export Sunday School'),
('import_sundayschool', 'Sunday School', 'Import Sunday School'),
('view_transfer_list', 'Transfers', 'View transfer list'),
('create_transfer', 'Transfers', 'Create transfer'),
('edit_transfer', 'Transfers', 'Edit transfer'),
('delete_transfer', 'Transfers', 'Delete transfer'),
('view_role_list', 'Roles & Permissions', 'View role list'),
('create_role', 'Roles & Permissions', 'Create role'),
('edit_role', 'Roles & Permissions', 'Edit role'),
('delete_role', 'Roles & Permissions', 'Delete role'),
('assign_role', 'Roles & Permissions', 'Assign role'),
('view_permission_list', 'Roles & Permissions', 'View permission list'),
('create_permission', 'Roles & Permissions', 'Create permission'),
('edit_permission', 'Roles & Permissions', 'Edit permission'),
('delete_permission', 'Roles & Permissions', 'Delete permission'),
('assign_permission', 'Roles & Permissions', 'Assign permission'),
('manage_roles', 'Roles & Permissions', 'Manage roles'),
('manage_permissions', 'Roles & Permissions', 'Manage permissions'),
('assign_permissions', 'Roles & Permissions', 'Assign permissions'),
('view_permission_audit_log', 'Roles & Permissions', 'View permission audit log'),
('use_permission_template', 'Roles & Permissions', 'Use permission template'),
('manage_permission_templates', 'Roles & Permissions', 'Manage permission templates'),
('view_activity_logs', 'Audit & Logs', 'View activity logs'),
('view_user_audit', 'Audit & Logs', 'View user audit'),
('create_user_audit', 'Audit & Logs', 'Create user audit'),
('edit_user_audit', 'Audit & Logs', 'Edit user audit'),
('delete_user_audit', 'Audit & Logs', 'Delete user audit'),
('export_audit', 'Audit & Logs', 'Export audit'),
('view_user_list', 'User Management', 'View user list'),
('create_user', 'User Management', 'Create user'),
('edit_user', 'User Management', 'Edit user'),
('delete_user', 'User Management', 'Delete user'),
('activate_user', 'User Management', 'Activate user'),
('deactivate_user', 'User Management', 'Deactivate user'),
('reset_password', 'User Management', 'Reset password'),
('forgot_password', 'User Management', 'Forgot password'),
('complete_registration', 'User Management', 'Complete registration'),
('complete_registration_admin', 'User Management', 'Complete registration as admin'),
('resend_registration_link', 'User Management', 'Resend registration link'),
('view_profile', 'User Management', 'View profile'),
('edit_profile', 'User Management', 'Edit profile'),
('access_ajax_bulk_members', 'AJAX/API', 'Access AJAX bulk members'),
('access_ajax_bulk_payment', 'AJAX/API', 'Access AJAX bulk payment'),
('access_ajax_bulk_payments_single_member', 'AJAX/API', 'Access AJAX bulk payments for a single member'),
('access_ajax_check_phone_duplicate', 'AJAX/API', 'Access AJAX check phone duplicate'),
('access_ajax_events', 'AJAX/API', 'Access AJAX events'),
('access_ajax_find_member_by_crn', 'AJAX/API', 'Access AJAX find member by CRN'),
('access_ajax_get_churches', 'AJAX/API', 'Access AJAX get churches'),
('access_ajax_get_classes_by_church', 'AJAX/API', 'Access AJAX get classes by church'),
('access_ajax_get_health_records', 'AJAX/API', 'Access AJAX get health records'),
('access_ajax_get_member_by_crn', 'AJAX/API', 'Access AJAX get member by CRN'),
('access_ajax_get_member_by_srn', 'AJAX/API', 'Access AJAX get member by SRN'),
('access_ajax_get_organizations_by_church', 'AJAX/API', 'Access AJAX get organizations by church'),
('access_ajax_get_person_by_id', 'AJAX/API', 'Access AJAX get person by ID'),
('access_ajax_get_total_payments', 'AJAX/API', 'Access AJAX get total payments'),
('access_ajax_hubtel_checkout', 'AJAX/API', 'Access AJAX Hubtel checkout'),
('access_ajax_members_by_church', 'AJAX/API', 'Access AJAX members by church'),
('access_ajax_payment_types', 'AJAX/API', 'Access AJAX payment types'),
('access_ajax_paystack_checkout', 'AJAX/API', 'Access AJAX Paystack checkout'),
('access_ajax_recent_payments', 'AJAX/API', 'Access AJAX recent payments'),
('access_ajax_resend_registration_link', 'AJAX/API', 'Access AJAX resend registration link'),
('access_ajax_resend_token_sms', 'AJAX/API', 'Access AJAX resend token SMS'),
('access_ajax_single_payment_member', 'AJAX/API', 'Access AJAX single payment member'),
('access_ajax_top_payment_types', 'AJAX/API', 'Access AJAX top payment types'),
('access_ajax_users_by_church', 'AJAX/API', 'Access AJAX users by church'),
('access_ajax_validate_member', 'AJAX/API', 'Access AJAX validate member'),
('view_bulk_payment', 'Bulk', 'View bulk payment UI'),
('submit_bulk_payment', 'Bulk', 'Submit bulk payment'),
('view_bulk_paystack_email_prompt', 'Bulk', 'View bulk Paystack email prompt'),
('upload_bulk_member', 'Bulk', 'Upload bulk member'),
('upload_bulk_organization', 'Bulk', 'Upload bulk organization'),
('edit_member_in_own_class', 'Advanced', 'Edit member in own class'),
('edit_member_in_own_church', 'Advanced', 'Edit member in own church'),
('view_report_for_own_org', 'Advanced', 'View report for own organization'),
('assign_leader_in_own_class', 'Advanced', 'Assign leader in own class'),
('make_payment_for_own_class', 'Advanced', 'Make payment for own class'),
('request_additional_permission', 'Advanced', 'Request additional permission'),
('view_system_logs', 'System', 'View system logs'),
('run_migrations', 'System', 'Run migrations'),
('access_admin_panel', 'System', 'Access admin panel'),
('backup_database', 'System', 'Backup database'),
('restore_database', 'System', 'Restore database'),
('manage_templates', 'System', 'Manage templates'),
('manage_settings', 'System', 'Manage settings');
