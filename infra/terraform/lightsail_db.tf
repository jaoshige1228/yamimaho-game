resource "aws_lightsail_database" "mysql" {
  relational_database_name = "${var.project_name}-db"
  availability_zone        = var.lightsail_availability_zone
  master_database_name     = var.db_master_database_name
  master_username          = var.db_master_username
  master_password          = var.db_password
  blueprint_id             = "mysql_8_0"
  bundle_id                = var.lightsail_db_bundle_id
  skip_final_snapshot      = true
}
