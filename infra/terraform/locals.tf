locals {
  # CloudFront のカスタムオリジンは IP 直指定不可。sslip.io で静的 IP に名前を付ける。
  lightsail_api_origin_domain = "${replace(aws_lightsail_static_ip.app.ip_address, ".", "-")}.sslip.io"

  # AWS マネージドポリシー ID（公式ドキュメントの値）
  cloudfront_cache_policy_caching_disabled = "4135ea2d-6df8-44a3-9df3-4b5a84be39ad"
  cloudfront_cache_policy_caching_optimized  = "658327ea-f89d-4fab-a63d-7e88639e58f6"
  cloudfront_origin_request_policy_all_viewer = "216adef6-5c7f-47e4-b989-5492eafa07d3"
}
