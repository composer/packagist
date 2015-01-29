template "#{release_path}/app/config/parameters.yml" do
  source "#{release_path}/deploy/parameters.yml.erb"
  owner "apache"
  group "apache"
  mode "0644"
  local true
end

composer_project "#{release_path}" do
    dev false
    quiet true
    prefer_dist false
    action :install
end
