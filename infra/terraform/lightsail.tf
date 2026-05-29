resource "aws_lightsail_instance" "app" {
  name              = "${var.project_name}-app"
  availability_zone = var.lightsail_availability_zone
  blueprint_id      = "amazon_linux_2023"
  bundle_id         = var.lightsail_instance_bundle_id
  user_data = templatefile("${path.module}/templates/bootstrap.sh.tpl", {
    opt_dir = var.app_opt_dir
  })

  lifecycle {
    ignore_changes = [user_data]
  }
}

resource "aws_lightsail_static_ip" "app" {
  name = "${var.project_name}-ip"
}

resource "aws_lightsail_static_ip_attachment" "app" {
  static_ip_name = aws_lightsail_static_ip.app.name
  instance_name  = aws_lightsail_instance.app.name
}

resource "aws_lightsail_instance_public_ports" "app" {
  instance_name = aws_lightsail_instance.app.name

  port_info {
    protocol  = "tcp"
    from_port = 80
    to_port   = 80
    cidrs     = ["0.0.0.0/0"]
  }
}
