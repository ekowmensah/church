# Canonical Permissions List (Comprehensive)

## Dashboard
- view_dashboard

## Members
- view_member
- create_member
- edit_member
- delete_member
- export_member
- import_member
- upload_member
- activate_member
- deactivate_member
- permanently_delete_member
- restore_deleted_member
- view_member_profile
- edit_member_profile
- view_member_organizations
- edit_member_organizations
- view_member_health_records
- view_member_events
- view_member_feedback
- respond_member_feedback
- convert_visitor_to_member

## Attendance
- view_attendance_list
- view_attendance_history
- mark_attendance
- edit_attendance
- delete_attendance
- export_attendance
- import_attendance
- view_attendance_report
- export_attendance_report

## Payments
- view_payment_list
- view_payment_history
- make_payment
- create_payment
- edit_payment
- delete_payment
- reverse_payment
- view_payment_reversal_log
- export_payment
- import_payment
- view_payment_bulk
- submit_bulk_payment
- view_payment_bulk_summary
- view_payment_types_today
- view_payment_total_today
- resend_payment_sms
- view_paystack_callback
- view_hubtel_callback

## Reports (Main)
- view_reports_dashboard
- view_audit_report
- view_event_report
- view_feedback_report
- view_health_report
- view_sms_report
- view_visitor_report
- view_membership_report
- view_payment_report
- export_payment_report
- export_health_report
- export_feedback_report
- export_membership_report
- export_visitor_report

## Reports (Detail)
- view_accumulated_payment_type_report
- view_age_bracket_payment_report
- view_age_bracket_report
- view_baptism_report
- view_bibleclass_payment_report
- view_class_health_report
- view_confirmation_report
- view_date_of_birth_report
- view_day_born_payment_report
- view_employment_status_report
- view_gender_report
- view_health_type_report
- view_individual_health_report
- view_individual_payment_report
- view_marital_status_report
- view_membership_status_report
- view_organisation_payment_report
- view_organisational_health_report
- view_organisational_member_report
- view_payment_made_report
- view_profession_report
- view_registered_by_date_report
- view_role_of_service_report
- view_zero_payment_type_report

## Bible Classes
- view_bibleclass_list
- create_bibleclass
- edit_bibleclass
- delete_bibleclass
- assign_bibleclass_leader
- remove_bibleclass_leader
- upload_bibleclass
- export_bibleclass

## Class Groups
- view_classgroup_list
- create_classgroup
- edit_classgroup
- delete_classgroup

## Organizations
- view_organization_list
- create_organization
- edit_organization
- delete_organization
- upload_organization
- export_organization

## Events
- view_event_list
- create_event
- edit_event
- delete_event
- register_event
- view_event_registration_list
- view_event_registration
- export_event

## Feedback
- view_feedback_list
- create_feedback
- edit_feedback
- delete_feedback
- respond_feedback
- view_memberfeedback_list
- view_memberfeedback_thread
- view_memberfeedback_my

## Health
- view_health_list
- create_health_record
- edit_health_record
- delete_health_record
- export_health
- import_health
- view_health_records
- view_health_form_prefill
- view_health_bp_graph

## SMS & Communication
- view_sms_log
- send_sms
- resend_sms
- view_sms_logs
- export_sms_logs
- manage_sms_templates
- view_sms_settings
- send_bulk_sms
- send_member_message
- view_visitor_sms_modal
- view_visitor_send_sms
- view_sms_bulk

## Visitors
- view_visitor_list
- create_visitor
- edit_visitor
- delete_visitor
- convert_visitor
- send_visitor_sms
- export_visitor

## Sunday School
- view_sundayschool_list
- create_sundayschool
- edit_sundayschool
- delete_sundayschool
- transfer_sundayschool
- view_sundayschool_view
- export_sundayschool
- import_sundayschool

## Transfers
- view_transfer_list
- create_transfer
- edit_transfer
- delete_transfer

## Roles & Permissions
- view_role_list
- create_role
- edit_role
- delete_role
- assign_role
- view_permission_list
- create_permission
- edit_permission
- delete_permission
- assign_permission
- manage_roles
- manage_permissions
- assign_permissions
- view_permission_audit_log
- use_permission_template
- manage_permission_templates

## Audit & Logs
- view_activity_logs
- view_user_audit
- create_user_audit
- edit_user_audit
- delete_user_audit
- export_audit

## Registration & User Management
- view_user_list
- create_user
- edit_user
- delete_user
- activate_user
- deactivate_user
- reset_password
- forgot_password
- complete_registration
- complete_registration_admin
- resend_registration_link
- view_profile
- edit_profile

## AJAX/API Endpoints
- access_ajax_bulk_members
- access_ajax_bulk_payment
- access_ajax_bulk_payments_single_member
- access_ajax_check_phone_duplicate
- access_ajax_events
- access_ajax_find_member_by_crn
- access_ajax_get_churches
- access_ajax_get_classes_by_church
- access_ajax_get_health_records
- access_ajax_get_member_by_crn
- access_ajax_get_member_by_srn
- access_ajax_get_organizations_by_church
- access_ajax_get_person_by_id
- access_ajax_get_total_payments
- access_ajax_hubtel_checkout
- access_ajax_members_by_church
- access_ajax_payment_types
- access_ajax_paystack_checkout
- access_ajax_recent_payments
- access_ajax_resend_registration_link
- access_ajax_resend_token_sms
- access_ajax_single_payment_member
- access_ajax_top_payment_types
- access_ajax_users_by_church
- access_ajax_validate_member

## Bulk Operations
- view_bulk_payment
- submit_bulk_payment
- view_bulk_paystack_email_prompt
- upload_bulk_member
- upload_bulk_organization

## Advanced/Contextual/Conditional
- edit_member_in_own_class
- edit_member_in_own_church
- view_report_for_own_org
- assign_leader_in_own_class
- make_payment_for_own_class
- request_additional_permission

## System Admin & Maintenance
- view_system_logs
- run_migrations
- access_admin_panel
- backup_database
- restore_database
- manage_templates
- manage_settings

---

*This list is exhaustive and should be updated as new features are added. Group permissions logically in the admin UI for easier management. Review for business-specific needs and add any missing actions as required.*
- view_health_report
- view_sms_report
- view_visitor_report
- view_membership_report

## Report Details
- view_age_bracket_report
- view_organisational_member_report
- view_marital_status_report
- view_employment_status_report
- view_baptism_report
- view_confirmation_report
- view_membership_status_report
- view_date_of_birth_report
- view_role_of_service_report
- view_registered_by_date_report
- view_profession_report
- view_gender_report
- view_class_health_report
- view_health_type_report
- view_individual_health_report
- view_individual_payment_report
- view_organisation_payment_report
- view_organisational_health_report
- view_payment_made_report
- view_day_born_payment_report
- view_bibleclass_payment_report
- view_zero_payment_type_report
- view_accumulated_payment_type_report

## SMS
- send_sms
- view_sms_logs
- resend_sms
- manage_sms_templates

## Roles & Permissions
- manage_roles
- manage_permissions
- assign_permissions
- view_permission_audit_log

## Templates
- use_permission_template
- manage_permission_templates

## Advanced/Contextual
- edit_member_in_own_class
- view_report_for_own_org
- request_additional_permission

---

*This is a draft. Add/remove as needed for your real modules and actions.*
