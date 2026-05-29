variable "aws_region" {
  type        = string
  description = "AWS region"
  default     = "ap-northeast-1"
}

variable "project_name" {
  type        = string
  description = "Resource name prefix (must be unique per AWS account; e.g. janken-card, yamimaho)"
}

variable "app_display_name" {
  type        = string
  description = "Laravel APP_NAME in generated app.env"
}

variable "app_opt_dir" {
  type        = string
  description = "App directory on Lightsail (e.g. /opt/janken, /opt/yamimaho)"
}

variable "lightsail_availability_zone" {
  type        = string
  description = "Lightsail AZ (e.g. ap-northeast-1a)"
  default     = "ap-northeast-1a"
}

variable "lightsail_instance_bundle_id" {
  type        = string
  description = "Lightsail instance bundle (e.g. small_3_0)"
  default     = "small_3_0"
}

variable "lightsail_db_bundle_id" {
  type        = string
  description = "Lightsail database bundle (e.g. micro_2_0)"
  default     = "micro_2_0"
}

variable "db_master_database_name" {
  type        = string
  description = "Lightsail MySQL database name"
}

variable "db_master_username" {
  type        = string
  description = "Lightsail MySQL master username"
}

variable "db_password" {
  type        = string
  description = "Lightsail MySQL master password"
  sensitive   = true
}

variable "app_key" {
  type        = string
  description = "Laravel APP_KEY (php artisan key:generate --show)"
  sensitive   = true
}
