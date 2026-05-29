output "cloudfront_url" {
  value       = "https://${aws_cloudfront_distribution.main.domain_name}"
  description = "Public URL for the application (Vue + API via CloudFront)."
}

output "cloudfront_domain_name" {
  value       = aws_cloudfront_distribution.main.domain_name
  description = "CloudFront distribution domain (for VITE / REVERB reference)."
}

output "cloudfront_distribution_id" {
  value       = aws_cloudfront_distribution.main.id
  description = "Use for cache invalidation after frontend deploy."
}

output "s3_bucket_name" {
  value       = aws_s3_bucket.frontend.id
  description = "S3 bucket for frontend static files (aws s3 sync)."
}

output "lightsail_static_ip" {
  value       = aws_lightsail_static_ip.app.ip_address
  description = "Lightsail static IP (SSH / troubleshooting)."
}

output "lightsail_api_origin_domain" {
  value       = local.lightsail_api_origin_domain
  description = "Hostname CloudFront uses to reach Lightsail (sslip.io → static IP)."
}

output "lightsail_instance_name" {
  value       = aws_lightsail_instance.app.name
  description = "Lightsail instance name for CLI."
}

output "database_endpoint" {
  value       = aws_lightsail_database.mysql.master_endpoint_address
  description = "Lightsail MySQL hostname for DB_HOST."
}

output "project_name" {
  value       = var.project_name
  description = "Stack identifier (matches environments/<name>.tfvars)."
}

output "app_opt_dir" {
  value       = var.app_opt_dir
  description = "Lightsail path for app.env and Docker (e.g. /opt/yamimaho)."
}

output "app_env_file" {
  value       = local_file.app_env.filename
  description = "Generated Laravel .env for Lightsail Docker (copy to app_opt_dir/.env)."
}
