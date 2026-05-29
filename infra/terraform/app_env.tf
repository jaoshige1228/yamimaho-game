resource "local_file" "app_env" {
  filename = "${path.module}/generated/${var.project_name}/app.env"
  content = templatefile("${path.module}/templates/app.env.tpl", {
    app_name    = var.app_display_name
    app_key     = var.app_key
    app_url     = "https://${aws_cloudfront_distribution.main.domain_name}"
    db_host     = aws_lightsail_database.mysql.master_endpoint_address
    db_database = var.db_master_database_name
    db_username = var.db_master_username
    db_password = var.db_password
  })

  depends_on = [aws_cloudfront_distribution.main]
}
